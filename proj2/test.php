

<?php

function usage() {
    echo "--help viz společný parametr všech skriptů v sekci 2.2;
• --directory=path testy bude hledat v zadaném adresáři (chybí-li tento parametr, skript
prochází aktuální adresář);
• --recursive testy bude hledat nejen v zadaném adresáři, ale i rekurzivně ve všech jeho
podadresářích;
--parse-script=file soubor se skriptem v PHP 8.1 pro analýzu zdrojového kódu v IPP-
code22 (chybí-li tento parametr, implicitní hodnotou je parse.php uložený v aktuálním adre-
sáři);
• --int-script=file soubor se skriptem v Python 3.8 pro interpret XML reprezentace kódu
v IPPcode22 (chybí-li tento parametr, implicitní hodnotou je interpret.py uložený v aktuál-
ním adresáři);
• --parse-only bude testován pouze skript pro analýzu zdrojového kódu v IPPcode22 (tento
parametr se nesmí kombinovat s parametry --int-only a --int-script), výstup s referenčním
výstupem (soubor s příponou out) porovnávejte nástrojem A7Soft JExamXML (viz [2]);
• --int-only bude testován pouze skript pro interpret XML reprezentace kódu v IPP-
code22 (tento parametr se nesmí kombinovat s parametry --parse-only, --parse-script
a --jexampath). Vstupní program reprezentován pomocí XML bude v souboru s příponou
src.
• --jexampath=path cesta k adresáři obsahující soubor jexamxml.jar s JAR balíčkem s ná-
strojem A7Soft JExamXML a soubor s konfigurací jménem options. Je-li parametr vynechán,
uvažuje se implicitní umístění /pub/courses/ipp/jexamxml/ na serveru Merlin, kde bude
test.php hodnocen. Koncové lomítko v path je případně nutno doplnit.
• --noclean během činnosti test.php nebudou mazány pomocné soubory s mezivýsledky, tj.
skript ponechá soubory, které vznikají při práci testovaných skriptů (např. soubor s výsledným
XML po spuštění parse.php atd.)";
}


function expectedFailure($rc) {
    if ($rc > 20) {
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
    if (is_file("$path.xml")){
        unlink("$path.xml");
    }

    if (is_file("$path.int")){
        unlink("$path.int");
    }
}



function diagnoseParserFailure($actual, $expected, &$simpleLog, &$advancedLog) {
    if ($actual == $expected) {
        return true;
    } else {
        $ef = expectedFailure($expected);

        if ($ef == 'parser') {
            $simpleLog .= "Parser failed, but with different exit code\n";
        } elseif ($ef == 'interpreter') {
            $simpleLog .= "Parser failed, but interpreter should have failed\n";
        } else {
            $simpleLog .= "Parser failed, but something else should have failed\n";
        }

        $advancedLog .= "Parser exited with code $actual, but expected return code is $expected\n";
        return false;
    }
}

function diagnoseInterpreterFailure($actual, $expected, &$simpleLog, &$advancedLog) {
    if ($actual == $expected) {
        return true;
    } else {
        $ef = expectedFailure($expected);

        if ($ef == 'interpreter') {
            $simpleLog .= "Interpreter failed, but with different exit code\n";
        } elseif ($ef == 'parser') {
            $simpleLog .= "Interpreter failed, but parser should have failed\n";
        } else {
            $simpleLog .= "Interpreter failed, but something else should have failed\n";
        }

        $advancedLog .= "Interpreter exited with code $retval, but expected return code is $rc";
        return false;
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
        $cmd = "php ".$options['parse-script']." <$path.src >$path.$xmlsuffix";
        exec($cmd, $output, $retval);

        if ($retval != 0) {
            return diagnoseParserFailure($retval, $rc, $simpleLog, $advancedLog);
        }
    }

    // If this is parse-only, run jexamxml and return
    if (array_key_exists('parse-only', $options)) {
        $jexamxml = $options['jexampath'].'/jexamxml.jar';
        $cmd = "java -jar $jexamxml $path.$xmlsuffix $path.out";
        exec($cmd, $output, $retval);

        if ($retval != 0) {
            $simpleLog .= "The output does not match the reference.";
            $advancedLog .= "The output does not match the reference.";
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

    $cmd = "python3 ".$options['int-script']." --source=$srcfile <$path.in >$path.$intsuffix";
    exec($cmd, $output, $retval);

    if ($retval != 0) {
        return diagnoseInterpreterFailure($retval, $rc, $simpleLog, $advancedLog);
    }

    // Compare the output using diff
    // If this is parse-only, run jexamxml and return
    if (array_key_exists('parse-only', $options)) {
        $jexamxml = $options['jexampath'].'/jexamxml.jar';
        $cmd = "diff $path.$intsuffix $path.out";
        exec($cmd, $output, $retval);

        if ($retval != 0) {
            $simpleLog .= "The output does not match the reference.";
            $advancedLog .= "The output does not match the reference.";
            return false;
        } else {
            return true;
        }
    }
}



function run($options) {
    if (array_key_exists('recursive', $options)) {
        $rdi = new RecursiveDirectoryIterator($options['directory']);
        $dirIterator = new RecursiveIteratorIterator($rdi);
    } else {
        $dirIterator = new DirectoryIterator($options['directory']);
    }

    $testDiv = '';

    $summaryTable = [];
    $numTests = 0;
    $numErrors = 0;

    foreach ($dirIterator as $file) {
        // last chcaracters of filename are .src
        if (preg_match('/.*\.src$/', $file)) {
            $path = substr($file, 0, -4);

            checkFiles($path);

            $simpleLog = '';
            $advancedLog = '';

            // Run the test, collect the logs
            $result = runTest($path, $options, $simpleLog, $advancedLog);

            if (!array_key_exists('noclean', $options)) {
                cleanup($path);
            }

            // Add the simple log to the statistics table
            if (!array_key_exists($simpleLog, $summaryTable)) {
                $summaryTable[$simpleLog] = 0;
            }
            $summaryTable[$simpleLog]++;

            $numTests++;
            $numErrors += $result ? 0 : 1;

            // If the test has failed save it to print later
            if ($result == 0) {
                $testDiv .= "<div class=\"testview\">\n";
                $testDiv .= " $path<br/>\n";
                $testDiv .= "$advancedLog<br/>\n";
                $testDiv .= "</div>\n";
            }
        }
    }

    $passed = $numTests - $numErrors;
    $totalPercentage = round($passed / $numTests);

    // Sort from the most common errors
    arsort($summaryTable);
    // Convert numbers to percentages
    foreach ($summaryTable as &$value) {
        $value = round($value / $numTests * 100);
    }

    echo "$passed out of $numTests ($totalPercentage%) tests passed\n";
    foreach ($summaryTable as $description => $percentage) {
        echo "$percentage% - $description";
    }

    // Print logs from individual tests
    echo $testDiv;
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
        'jexampath' => '/pub/courses/ipp/jexamxml',
    );

    $options = getopt($shortopts, $longopts);
    $options += $defaults;
    //var_dump($options);

    if (array_key_exists('help', $options) or
        array_key_exists('h', $options)) {
        usage();
        exit(0);
    }

    if (array_key_exists('parse-only', $options) and
        array_key_exists('int-only', $options)) {
        echo '--parse-only and --int-only options cannot be combined';
        exit(10);
    }

    return $options;
}

ini_set('display_errors', 'stderr');

$options = getOptions();

echo '<!DOCTYPE html>
<html lang="">
  <head>
    <meta charset="utf-8">
    <title>IPP test results</title>
    <style>
      body {
        font-family: \'Helvetica\', \'Arial\', sans-serif;
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

    </style>
  </head>

  <body>
    <h1>Test results</h1>';

run($options);

echo '  </body>
</html>';

?>


