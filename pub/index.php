<?php
// throwable interface for errors - throws exception on error
function error($error, $status = 500) {
    if (is_array($error)) {
        $message = $error['message'] ?? 'Unknown error.';
        $file    = $error['file'] ?? __FILE__;
        $line    = $error['line'] ?? __LINE__;
        $status  = $error['status'] ?? $status;
        $code    = $error['code'] ?? $status;
    } else {
        $message = $error;
    }

    $error = new Error($message, $status);

    $error->PFUNCS_ERROR = [
        'message' => $message,
        'file'    => $file ?? __FILE__,
        'line'    => $line ?? __LINE__,
        'trace'   => debug_backtrace(false),
        'status'  => $status,
        'code'    => $code ?? $status
    ];

    throw $error;
}

// throwable interface for errors - throws exception on error
set_error_handler(function ($code, $message, $file, $line) {
    error([
        'message' => $message,
        'file'    => $file,
        'line'    => $line,
        'status'  => 500,
        'code'    => $code
    ]);
}, E_ALL | E_STRICT | E_WARNING | E_NOTICE);

// define the content extension for error handling
preg_match(
    '/\.(?P<ext>\w+)$/', parse_url(trim($_SERVER['REQUEST_URI'] ?? '', '/'),
        PHP_URL_PATH),
    $_
);

define('PFUNCS_EXTENSION', $_['ext'] ?? 'html');

// define a uid for logging and stuff - specific to the current request being processed
define('PFUNCS_UID', uniqid());

// handles exception if not caught by mvc
set_exception_handler(function (Throwable $exception) {
    // check if exception was thrown by mvc
    $error = $exception->PFUNCS_ERROR ?? [];

    // set values accordingly
    $message = $exception->getMessage();

    $code   = $error['code']    ?? $exception->getCode();
    $file   = $error['file']    ?? $exception->getFile();
    $line   = $error['line']    ?? $exception->getLine();
    $trace  = $error['trace']   ?? $exception->getTrace();
    $status = $error['status']  ?? 500;

    $file = basename($file);

    // build trace into readable string
    $trace = array_map(function ($entry) {
        $function = $entry['function'] ?? '?';
        $line     = $entry['line']     ?? '?';
        $file     = $entry['file']     ?? '?';

        if ($file !== '?') {
            $file = basename($file);
        }

        $args = implode(', ', array_map(function ($arg) {
            return is_scalar($arg) ? strval($arg) : gettype($arg);
        }, $entry['args'] ?? []));

        return "$file:$line $function($args)";
    }, $trace);

    // buffer the whole error output
    $output = PFUNCS_UID . " $file:$line $code $message\n\t" . implode("\n\t", $trace) . "\n";

    // log it
    error_log($output);

    // set the http status
    http_response_code($status);

    // output the error depending on the content type
    switch (PFUNCS_EXTENSION) {
        case 'json':
            die(json_encode([
                'uid'     => PFUNCS_UID,
                'file'    => $file,
                'line'    => $line,
                'code'    => $code,
                'message' => $message,
                'trace'   => $trace,
                'error'   => $output
            ], JSON_PRETTY_PRINT));
        case 'xml':
            die(
                '<?xml version="1.0" encoding="UTF-8"?><error>' .
                htmlspecialchars($output, ENT_XML1, 'UTF-8') .
                '</error>'
            );
        case 'txt':
            die($output);
        case 'js':
            die('if(typeof console.log != "undefined")console.log("' . addslashes($output) . '");');
        case 'css':
            die("/*\n$output\n*/");
        case 'html':
        default:
    }

    die('<html><body><pre>' . htmlentities($output) . '</pre></body></html>');
});

// set some path constants
define('PFUNCS_SRC', $_SERVER['SERVER_NAME'] ?? 'localhost');
define('PFUNCS_ROOT', realpath(__DIR__ . '/../src/' . PFUNCS_SRC));
define('PFUNCS_CONFIGS', realpath(PFUNCS_ROOT . '/configs.php'));
define('PFUNCS_PHP', realpath(PFUNCS_ROOT . '/php'));
define('PFUNCS_CONTENT', realpath(PFUNCS_ROOT . '/' . PFUNCS_EXTENSION));

// if there are configs then load them
if (file_exists(PFUNCS_CONFIGS)) {
    include_once(PFUNCS_CONFIGS);
}

// set the timezone to utc by default
date_default_timezone_set('UTC');

// begin the session
session_start();

// parse the request contents into $_REQUEST
$_REQUEST = array_merge($_REQUEST, parse_str(file_get_contents('php://input'), $request) ?? []);

// store cookies as json array so $_COOKIE can be used like $_SESSION
if (!array_key_exists(PFUNCS_SRC, $_COOKIE)) {
    $_COOKIE[PFUNCS_SRC] = '{}';
}

$_COOKIE  = json_decode($_COOKIE[PFUNCS_SRC], true);

register_shutdown_function(function () {
    $_COOKIE[PFUNCS_SRC] = json_encode($_COOKIE);
});

// parse out the controller and action
$_ = explode('/', parse_url(trim($_SERVER['REQUEST_URI'] ?? '', '/'), PHP_URL_PATH));

define('PFUNCS_CONTROLLER', preg_replace('/\.\w+$/i', '', $_[0] ?? 'index'));
define('PFUNCS_ACTION', PFUNCS_CONTROLLER === 'index' ? null : preg_replace('/\.\w+$/i', '', $_[1] ?? 'index'));

// is dynamic content?
if (!in_array(PFUNCS_EXTENSION, ['html', 'txt', 'json', 'xml', 'js', 'css'])) {
    if (
        file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '.' . PFUNCS_EXTENSION) ||
        file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '/' . PFUNCS_ACTION . '.' . PFUNCS_EXTENSION)
    ) {
        passthru($_);

        return;
    }

    error('Not Found', 404);
}

// include the pfuncs
include_once(realpath(__DIR__ . '/../vendor/autoload.php'));

// load all the php code
foreach ([
    PFUNCS_PHP, //<< root php
    PFUNCS_PHP . '/' . PFUNCS_CONTROLLER, //<< controller specific php
    PFUNCS_PHP . '/' . PFUNCS_CONTROLLER . '/' . PFUNCS_ACTION //<< action specific php
] as $_) {
    $_ = realpath($_);

    if (!$_) {
        continue;
    }

    foreach (array_keys(scan_dir($_)) as $_) {
        if (!is_dir($_)) {
            include_once_if_exists($_);
        }
    }
}

// load the dynamic content
try { //<< header/footer
    ob_start();

    if (
        file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '/.header.' . PFUNCS_EXTENSION) ||
        file_exists($_ = "$_.php")
    ) {
        include($_);
    }

    try { //<< body
        ob_start();

        if (
            file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '/' . PFUNCS_ACTION . '.' . PFUNCS_EXTENSION) ||
            file_exists($_ = "$_.php") ||
            file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '/.body.' . PFUNCS_EXTENSION) ||
            file_exists($_ = "$_.php") ||
            file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '.' . PFUNCS_EXTENSION) ||
            file_exists($_ = "$_.php") ||
            file_exists($_ = PFUNCS_CONTENT . '/.body.' . PFUNCS_EXTENSION) ||
            file_exists($_ = "$_.php")
        ) {
            include($_);
        } else {
            error('Not Found', 404);
        }

        ob_end_flush();
    } catch (Throwable $throwable) { //<< body fail
        ob_end_clean();

        if (
            file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '/' . PFUNCS_ACTION . '/.error.' . PFUNCS_EXTENSION) ||
            file_exists($_ = "$_.php") ||
            file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '/.error.' . PFUNCS_EXTENSION) ||
            file_exists($_ = "$_.php")
        ) {
            include($_);
        } else {
            throw $throwable;
        }
    }

    if (
        file_exists($_ = PFUNCS_CONTENT . '/' . PFUNCS_CONTROLLER . '/.footer.' . PFUNCS_EXTENSION) ||
        file_exists($_ = "$_.php")
    ) {
        include($_);
    }

    ob_end_flush();
} catch (Throwable $throwable) { //<< header/footer fail
    ob_end_clean();

    if (
        file_exists($_ = PFUNCS_CONTENT . '/.error.' . PFUNCS_EXTENSION) ||
        file_exists($_ = "$_.php")
    ) {
        include($_);
    } else {
        throw $throwable;
    }
}

unset($_);