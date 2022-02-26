<?php

const ERR_BADARG = 10;
const ERR_INFILE = 11;
const ERR_OUTFILE = 12;

const ERR_HEADER = 21;
const ERR_OPCODE = 22;
const ERR_LEXSYN = 23;

$order = 1;

function usage() {
    echo "Reads IPPcode from stdin and prints XML representation on stdout.
    options: --help Prints this message\n";
}

// returns [parsed, type]
// parsed is the argument in output format
// type can be: int, bool, string, nil, label, type, var
function parseArgument($str, $nt) {
    $parts = explode('@', $str, 2);

    // Only label and type has no @ signs
    if (count($parts) == 1) {
        $parsed = $parts[0];

        if ($nt == 'label') {
            if (!preg_match('/^[a-zA-Z\-_$&%*!?][a-zA-Z0-9\-_$&%*!?]*$/', $parts[0])) {
                fwrite(STDERR, "`$parts[0]` is not a legal label.\n");
                exit(ERR_LEXSYN);
            }
            $type = 'label';

        } elseif ($nt == 'type') {
            if (($parsed == 'int') || ($parsed == 'string') || ($parsed == 'bool')) {
                $type = 'type';
            } else {
                fwrite(STDERR, "Error.\n");
                exit(ERR_LEXSYN);
            }
        } else {
            fwrite(STDERR, "Error.\n");
            exit(ERR_LEXSYN);
        }

    } else {
        $context = $parts[0];

        switch ($context) {
            case 'GF':
            case 'LF':
            case 'TF':
                $type = 'var';
                $parsed = $parts[0].'@'.$parts[1];

                if (!preg_match('/^[a-zA-Z\-_$&%*!?][a-zA-Z0-9\-_$&%*!?]*$/', $parts[1])) {
                    fwrite(STDERR, "`$parts[1]` is not a legal variable identifier.\n");
                    exit(ERR_LEXSYN);
                }

                if (($nt != 'symb') && ($nt != 'var')) {
                    fwrite(STDERR, "Expected $nt, got $type.\n");
                    exit(ERR_LEXSYN);
                }
                break;

            case 'int':
                $type = 'int';
                $parsed = $parts[1];

                if (!preg_match('/^(\\+|\\-)?[0-9]+$/', $parsed)) {
                    fwrite(STDERR, "Int cannot have `$parts[1]` value.\n");
                    exit(ERR_LEXSYN);
                }

                if ($nt != 'symb') {
                    fwrite(STDERR, "Expected $nt, got $type.\n");
                    exit(ERR_LEXSYN);
                }
                break;

            case 'bool':
                $type = 'bool';
                $parsed = strtolower($parts[1]);

                if (($parsed != 'true') && ($parsed != 'false')) {
                    fwrite(STDERR, "Bool cannot have `$parts[1]` value.\n");
                    exit(ERR_LEXSYN);
                }

                if ($nt != 'symb') {
                    fwrite(STDERR, "Expected $nt, got $type.\n");
                    exit(ERR_LEXSYN);
                }
                break;

            case 'string':
                $type = 'string';
                $parsed = $parts[1];

                // Check if every escape sequence is valid
                $escapes = array();
                $escapesOK = true;
                if (preg_match_all('/\\\\/', $parsed) == preg_match_all('/\\\\[0-9]{3}/', $parsed, $escapes)) {
                    foreach ($escapes[0] as $esc) {
                        $number = substr($esc, 1);
                        if (intval($number) > 255) {
                            $escapesOK = false;
                            break;
                        }
                    }
                } else {
                    $escapesOK = false;
                }
                if (!$escapesOK) {
                    fwrite(STDERR, "Bad escape sequences in strings\n");
                    exit(ERR_LEXSYN);
                }

                if ($nt != 'symb') {
                    fwrite(STDERR, "Expected $nt, got $type.\n");
                    exit(ERR_LEXSYN);
                }
                break;

            case 'nil':
                $type = 'nil';
                $parsed = 'nil';

                if ($parts[1] != 'nil') {
                    fwrite(STDERR, "`$parts[1]` is not a legal value of nil type.\n");
                    exit(ERR_LEXSYN);
                }

                if ($nt != 'symb') {
                    fwrite(STDERR, "Expected $nt, got $type.\n");
                    exit(ERR_LEXSYN);
                }
                break;

            default:
                fwrite(STDERR, "Data type `$context` not supported.\n");
                exit(ERR_LEXSYN);
        }
    }

    return [$parsed, $type];
}


class Instruction {
    public $opcode;
    public $arglist;

    function __construct(string $opcode, array $arglist) {
        $this->opcode = $opcode;

        // check if arguments are ok
        for ($i=0; $i<count($arglist); $i++) {
            if (!array_key_exists($i, $arglist)) {
                throw new Exception('Wrong list of arguments');
            }
            if (!is_string($arglist[$i])) {
                throw new Exception('Wrong list of arguments');
            }
        }
        $this->arglist = $arglist;
    }

    function generateXML(XMLWriter $xw, array $args) {
        $xw->startElement('instruction');

        $xw->startAttribute('order');
        global $order;
        $xw->text($order++);
        $xw->endAttribute();

        $xw->startAttribute('opcode');
        $xw->text($this->opcode);
        $xw->endAttribute();

        // Arguments
        $i=0;
        while (true) {
            $expectedExistence = array_key_exists($i, $this->arglist);
            $actualExistence = array_key_exists($i+1, $args);

            if ($expectedExistence != $actualExistence) {
                fwrite(STDERR, "Wrong arguments of instruction $this->opcode.\n");
                exit(ERR_LEXSYN);
            }

            if ($expectedExistence == false) {
                break;
            }

            $expectedNT = $this->arglist[$i];
            [$parsed, $type] = parseArgument($args[$i+1], $expectedNT);

            $xw->startElement("arg".($i+1));
            $xw->startAttribute('type');
            $xw->text($type);
            $xw->endAttribute();
            $xw->text($parsed);
            $xw->endElement();

            $i++;
        }

        $xw->endElement();
    }
}


function parseLine(XMLWriter $xw, string $line, bool &$expectingHeader) {
    $instructions = [
        'MOVE' => new Instruction('MOVE', ['var', 'symb']),
        'CREATEFRAME' => new Instruction('CREATEFRAME', []),
        'PUSHFRAME' => new Instruction('PUSHFRAME', []),
        'POPFRAME' => new Instruction('POPFRAME', []),
        'DEFVAR' => new Instruction('DEFVAR', ['var']),
        'CALL' => new Instruction('CALL', ['label']),
        'RETURN' => new Instruction('RETURN', []),

        'PUSHS' => new Instruction('PUSHS', ['symb']),
        'POPS' => new Instruction('POPS', ['var']),

        'ADD' => new Instruction('ADD', ['var', 'symb', 'symb']),
        'SUB' => new Instruction('SUB', ['var', 'symb', 'symb']),
        'MUL' => new Instruction('MUL', ['var', 'symb', 'symb']),
        'IDIV' => new Instruction('IDIV', ['var', 'symb', 'symb']),
        'LT' => new Instruction('LT', ['var', 'symb', 'symb']),
        'GT' => new Instruction('GT', ['var', 'symb', 'symb']),
        'EQ' => new Instruction('EQ', ['var', 'symb', 'symb']),
        'AND' => new Instruction('AND', ['var', 'symb', 'symb']),
        'OR' => new Instruction('OR', ['var', 'symb', 'symb']),
        'NOT' => new Instruction('NOT', ['var', 'symb']),
        'INT2CHAR' => new Instruction('INT2CHAR', ['var', 'symb']),
        'STRI2INT' => new Instruction('STRI2INT', ['var', 'symb', 'symb']),

        'READ' => new Instruction('READ', ['var', 'type']),
        'WRITE' => new Instruction('WRITE', ['symb']),

        'CONCAT' => new Instruction('CONCAT', ['var', 'symb', 'symb']),
        'STRLEN' => new Instruction('STRLEN', ['var', 'symb']),
        'GETCHAR' => new Instruction('GETCHAR', ['var', 'symb', 'symb']),
        'SETCHAR' => new Instruction('SETCHAR', ['var', 'symb', 'symb']),

        'TYPE' => new Instruction('TYPE', ['var', 'symb']),

        'LABEL' => new Instruction('LABEL', ['label']),
        'JUMP' => new Instruction('JUMP', ['label']),
        'JUMPIFEQ' => new Instruction('JUMPIFEQ', ['label', 'symb', 'symb']),
        'JUMPIFNEQ' => new Instruction('JUMPIFNEQ', ['label', 'symb', 'symb']),
        'EXIT' => new Instruction('EXIT', ['symb']),

        'DPRINT' => new Instruction('DPRINT', ['symb']),
        'BREAK' => new Instruction('BREAK', []),
    ];

    // Remove any comments (split by '#' and keep only the first part)
    $line = trim(explode('#', $line)[0]);

    // There is nothing to parse
    if ($line == '') {
        return;
    }

    // Split by whitespace
    $parts = preg_split('/\s+/', $line);
    $opcode = strtoupper($parts[0]);

    if ($expectingHeader) {
        if (strcasecmp($opcode, '.IPPcode22') == 0) {
            $expectingHeader = 0;
            return;
        } else {
            fwrite(STDERR, "Unexpected header `$opcode`.\n");
            exit(ERR_HEADER);
        }
    }

    // If there is an instruction on this line
    if (array_key_exists($opcode, $instructions)) {
        $instructions[$opcode]->generateXML($xw, $parts);
    } else {
        fwrite(STDERR, "Opcode `$opcode` does not exist.\n");
        exit(ERR_OPCODE);
    }
}

function parseInput($inFile) {
    $xw = new XMLWriter();
    $xw->openMemory();
    $xw->setIndent(1);

    $xw->startDocument('1.0', 'UTF-8');
    $xw->startElement('program');

    // language attribute
    $xw->startAttribute('language');
    $xw->text('IPPcode22');
    $xw->endAttribute();

    $expectingHeader = true;
    // parse each line with instruction
    while(!feof($inFile)) {
        $line = stream_get_line($inFile, 0, "\n");
        parseLine($xw, $line, $expectingHeader);
    }

    // end the document
    $xw->endElement();
    $xw->endDocument();

    // Write the xml to the output
    echo $xw->outputMemory();
}


ini_set('display_errors', 'stderr');

$shortopts = "h";
$longopts = array(
    "help",
);

$options = getopt($shortopts, $longopts);

if (array_key_exists('help', $options) or
    array_key_exists('h', $options)) {
    usage();
    exit(0);
}

parseInput(STDIN);

?>
