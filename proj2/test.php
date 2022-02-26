<?php

const ERR_BADARG = 10;
const ERR_INFILE = 11;
const ERR_OUTFILE = 12;

const ERR_BADPATH = 41;

const PHP = 'php8.1';
const PYTHON = 'python3';

function usage() {
    echo "--help Print this message.
--directory=path Specify the directory with tests.
--recursive Search for test in evrery subfolder.
--parse-script=file The parser PHP script (implicit value is parse.php).
--int-script=file The interpreter python script (implicit value is interpret.py).
--parse-only Tests only the parser script. Output is compared using JExamXML. Cannot be combined with --int-only, --int-script.
--int-only Tests only the parser script. Cannot be combined with --parse-only, --parse-script.
--jexampath=path Path to folder containing jexamxml.jar (implicit value /pub/courses/ipp/jexamxml/).
--noclean Temporary files will not be delted after test is done.\n";
}


function expectedFailure($rc) {
    if ($rc == 0) {
        return 'success';
    } elseif ($rc < 20) {
        return 'unknown';
    } elseif ($rc < 30) {
        return 'parser';
    } elseif ($rc < 60) {
        return 'interpreter';
    } else {
        return 'unknown';
    }
}


function checkFiles($path) {
    if (!is_file("$path.in")){
        touch("$path.in");
    }

    if (!is_file("$path.out")){
        touch("$path.out");
    }

    if (!is_file("$path.rc")){
        file_put_contents("$path.rc", "0\n");
    }
}


function cleanup($path) {
    $toRemove = ["$path.xml", "$path.int", "$path.err"];

    foreach ($toRemove as $file) {
        if (is_file("$file")){
            unlink("$file");
        }
    }
}

function getHtmlContents($filename, $heading) {
    $output = '';

    $contents = file_get_contents($filename);

    if ($contents != '') {
        $output .= '<h5 class="codeheading">'.$heading.'</h5>';
        $output .= '<p class="codeview" style="color:White">';
        $output .= nl2br(htmlentities($contents));
        $output .= '</p>';
    }

    return $output;
}

function getErrLog($path) {
    return getHtmlContents("$path.err", "STDERR of failed component");
}

function getSrcLog($path) {
    return getHtmlContents("$path.src", "Source file");
}

function diagnoseParserFailure($actual, $expected, &$simpleLog, &$advancedLog, $path) {
    if ($actual == $expected) {
        return true;
    } else {
        $ef = expectedFailure($expected);

        if ($ef == 'success') {
            $simpleLog .= "Parser failed, but program was supposed to run\n";
        } elseif ($ef == 'parser') {
            $simpleLog .= "Parser failed, but with different exit code\n";
        } elseif ($ef == 'interpreter') {
            $simpleLog .= "Parser failed, but interpreter should have failed\n";
        } else {
            $simpleLog .= "Parser failed, but something else should have failed\n";
        }

        $advancedLog .= "<p>Parser exited with code $actual, but expected return code is $expected</p>\n";
        $advancedLog .= getErrLog($path);
        $advancedLog .= getSrcLog($path);

        return false;
    }
}

function diagnoseInterpreterFailure($actual, $expected, &$simpleLog, &$advancedLog, $path) {
    if ($actual == $expected) {
        return true;
    } else {
        $ef = expectedFailure($expected);

        if ($ef == 'success') {
            $simpleLog .= "Interpreter failed, but program was supposed to run\n";
        } elseif ($ef == 'interpreter') {
            $simpleLog .= "Interpreter failed, but with different exit code\n";
        } elseif ($ef == 'parser') {
            $simpleLog .= "Interpreter failed, but parser should have failed\n";
        } else {
            $simpleLog .= "Interpreter failed, but something else should have failed\n";
        }

        $advancedLog .= "<p>Interpreter exited with code $actual, but expected return code is $expected</p>";
        $advancedLog .= getErrLog($path);
        $advancedLog .= getSrcLog($path);
        $advancedLog .= getHtmlContents("$path.in", "Interpreter STDIN");

        return false;
    }
}

// outsuffix can be xml or int
function diagnoseCmpFailure($path, $outsuffix, &$simpleLog, &$advancedLog) {
    $simpleLog .= "The output does not match the reference.";

    $advancedLog .= "<p>The output does not match the reference.</p>";
    $advancedLog .= '<h5 class="codeheading">Expected output</h5>';
    $advancedLog .= '<p class="codeview" style="color:lime">';
    $advancedLog .= nl2br(htmlentities(file_get_contents("$path.out")));
    $advancedLog .= '</p>';

    $advancedLog .= '<h5 class="codeheading">Actual output</h5>';
    $advancedLog .= '<p class="codeview" style="color:lightcoral">';
    $advancedLog .= nl2br(htmlentities(file_get_contents("$path.$outsuffix")));
    $advancedLog .= '</p>';

    $advancedLog .= getSrcLog($path);
}

class SortedIterator extends SplHeap {
    public function __construct(Iterator $iterator) {
        foreach ($iterator as $item) {
            $this->insert($item);
        }
    }
    public function compare($b, $a) {
        return strcmp($a->getRealpath(), $b->getRealpath());
    }
}

function runTest($path, $options, &$simpleLog, &$advancedLog) {
    $xmlsuffix = 'xml';
    $intsuffix = 'int';

    $rc = intval(file_get_contents("$path.rc"));

    $output = null;
    $retval = null;

    // Parse the ippcode. If int-only is set, skip this stage
    if (!array_key_exists('int-only', $options)) {
        if (!file_exists($options['parse-script'])) {
            fwrite(STDERR, "Invalid path to parser: " . $options['parse-script'] . "\n");
            exit(ERR_BADPATH);
        }

        $cmd = PHP." ".$options['parse-script']." <$path.src >$path.$xmlsuffix 2>$path.err";
        exec($cmd, $output, $retval);

        if ($retval != 0) {
            return diagnoseParserFailure($retval, $rc, $simpleLog, $advancedLog, $path);
        }
    }

    // If this is parse-only, run jexamxml and return
    if (array_key_exists('parse-only', $options)) {
        // The parser should have failed, but did not
        if ($rc != 0) {
            $simpleLog .= "Parser succeeded, but should have failed\n";
            $advancedLog .= "<p>Parser succeeded, but should have exited with code $rc</p>";
            $advancedLog .= getSrcLog($path);
            return false;
        }

        $jexamxml = $options['jexampath'].'/jexamxml.jar';
        if (!file_exists($jexamxml)) {
            fwrite(STDERR, "Invalid path to jexamxml: $path\n");
            exit(ERR_BADPATH);
        }
        $cmd = "java -jar $jexamxml $path.$xmlsuffix $path.out 2>/dev/null";
        exec($cmd, $output, $retval);

        if ($retval != 0) {
            diagnoseCmpFailure($path, 'xml', $simpleLog, $advancedLog);
            return false;
        } else {
            return true;
        }
    }

    // Run the interpreter
    if (array_key_exists('int-only', $options)) {
        $srcfile = "$path.src";
    } else {
        $srcfile = "$path.xml";
    }
    if (!file_exists($options['int-script'])) {
        fwrite(STDERR, "Invalid path to interpreter: $path\n");
        exit(ERR_BADPATH);
    }
    $cmd = PYTHON." ".$options['int-script']." --source=$srcfile <$path.in >$path.$intsuffix 2>$path.err";
    exec($cmd, $output, $retval);

    if ($retval != 0) {
        return diagnoseInterpreterFailure($retval, $rc, $simpleLog, $advancedLog, $path);
    }

    // The parser should have failed, but did not
    if ($rc != 0) {
        $simpleLog .= "Interpreter succeeded, but should have failed\n";
        $advancedLog .= "<p>Interpreter succeeded, but should have exited with code $rc</p>";
        $advancedLog .= getSrcLog($path);
        return false;
    }
    // Compare the output using diff
    $cmd = "diff $path.$intsuffix $path.out";
    exec($cmd, $output, $retval);

    if ($retval != 0) {
        diagnoseCmpFailure($path, 'int', $simpleLog, $advancedLog);
        $advancedLog .= getHtmlContents("$path.in", "Interpreter STDIN");
        return false;
    } else {
        return true;
    }
}

function getPathArray($root, $recursive) {
    if (!file_exists($root)) {
        fwrite(STDERR, "Invalid path: $root\n");
        exit(ERR_BADPATH);
    }

    if ($recursive) {
        $rdi = new RecursiveDirectoryIterator($root);
        $dirIterator = new RecursiveIteratorIterator($rdi);
    } else {
        $dirIterator = new DirectoryIterator($root);
    }

    $arr = [];

    foreach ($dirIterator as $file) {
        if ($file->getExtension() == 'src') {
            $testname = $file->getPath() . '/' . $file->getFilename();
            $testname = substr($testname, 0, -4);
            array_push($arr, $testname);
        }
    }

    sort($arr);
    return $arr;
}

function run($options) {
    $testDiv = '';
    $summaryTable = [];
    $numTests = 0;
    $numErrors = 0;

    $pathArray = getPathArray($options['directory'], array_key_exists('recursive', $options));

    foreach ($pathArray as $path) {
        fwrite(STDERR, "$path\n");
        checkFiles($path);

        $simpleLog = '';
        $advancedLog = '';

        // Run the test, collect the logs
        $result = runTest($path, $options, $simpleLog, $advancedLog);

        if (!array_key_exists('noclean', $options)) {
            cleanup($path);
        }

        $numTests++;

        // If the test has failed save it to print later
        if ($result == false) {
            $numErrors++;

            // Add the simple log to the statistics table
            if (!array_key_exists($simpleLog, $summaryTable)) {
                $summaryTable[$simpleLog] = 0;
            }
            $summaryTable[$simpleLog]++;

            // Generate the html for failed test
            $testDiv .= "<div class=\"testview\">\n";

            $testDiv .= "<p>$path</p>\n";
            $testDiv .= $advancedLog;
            $testDiv .= "</div>\n";
        }
    }

    if ($numTests == 0) {
        fwrite(STDERR, "No tests were found in directory `" . $options['directory'] . "`\n");
        exit(0);
    }

    $passed = $numTests - $numErrors;
    $totalPercentage = round($passed / $numTests * 100);

    // Sort from the most common errors
    arsort($summaryTable);
    // Convert numbers to percentages
    foreach ($summaryTable as &$value) {
        $value = round($value / $numErrors * 100);
    }

    printHeader();
    $color = ($numErrors == 0) ? 'green' : 'red';
    echo "<p style=\"color:$color\">\n";
    echo "$passed out of $numTests ($totalPercentage%) tests passed\n";
    echo "</p>";

    if ($numErrors != 0) {
        echo "<p>";
        foreach ($summaryTable as $description => $percentage) {
            echo "$percentage%\t$description<br/>";
        }
        echo "</p>";

        // Print logs from individual tests
        echo "<h3>Failed tests</h3>\n";
        echo "<div>$testDiv</div>";
    }
    printFooter();
}


function getOptions() {
    $shortopts = 'h';

    $longopts = array(
        'help',
        'directory:',
        'recursive',
        'parse-script:',
        'int-script:',
        'parse-only',
        'int-only',
        'jexampath:',
        'noclean',
    );

    $defaults = array(
        'directory' => '.',
        'parse-script' => 'parse.php',
        'int-script' => 'interpret.py',
        'jexampath' => '/pub/courses/ipp/jexamxml/',
    );

    $options = getopt($shortopts, $longopts);

    if (array_key_exists('help', $options) or array_key_exists('h', $options)) {
        usage();
        exit(0);
    }

    if (array_key_exists('parse-only', $options)) {
        if (array_key_exists('int-only', $options)) {
            fwrite(STDERR, "--parse-only and --int-only options cannot be combined\n");
            exit(ERR_BADARG);
        }
        if (array_key_exists('int-script', $options)) {
            fwrite(STDERR, "--parse-only and --int-script options cannot be combined\n");
            exit(ERR_BADARG);
        }
    }

    if (array_key_exists('int-only', $options)) {
        if (array_key_exists('parse-only', $options)) {
            fwrite(STDERR, "--int-only and --parse-only options cannot be combined\n");
            exit(ERR_BADARG);
        }
        if (array_key_exists('parse-script', $options)) {
            fwrite(STDERR, "--int-only and --parse-script options cannot be combined\n");
            exit(ERR_BADARG);
        }
    }

    $options += $defaults;
    return $options;
}

function printHeader() {
    echo '<!DOCTYPE html>
    <html lang="">
    <head>
        <meta charset="utf-8">
        <title>IPP test results</title>
        <style>
        body {
            font-family: "Helvetica", "Arial", sans-serif;
            color: #444444;
            background-color: #FAFAFA;
            background-color: Seashell;
            padding: 15px 40px;
        }

        .testview {
            background-color: White;
            border-radius: 8px;
            padding: 5px 30px;
            margin-bottom: 15px;
        }

        .codeview {
            background-color: #444444;
            color: white;
            font-family: "Lucida Console", "Menlo", "Monaco", "Courier", monospace;
            border-radius: 10px;
            padding: 10px 15px;
            margin-top: 0px;
            margin-bottom: 15px;
        }

        .codeheading {
            margin-bottom: 0px;
            margin-left: 5px;
        }

        </style>
    </head>

    <body>
        <h1>Test results</h1>';
}

function printFooter() {
    echo " </body></html>\n";
}

ini_set('display_errors', 'stderr');

$options = getOptions();

run($options);



?>
