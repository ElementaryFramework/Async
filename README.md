# ElementaryFramework Async Library

A full-featured PHP library for asynchronous operations with support for promises, fibers, and modern async patterns. Built for PHP 8.4+.

## Features

- **Promise/A+ Compatible**: Full implementation of the Promise/A+ specification
- **Fiber-Based Execution**: Leverages PHP 8.1+ Fibers for true asynchronous operations
- **Event Loop**: Built-in event loop for managing async operations and timers
- **Cancellation Support**: Comprehensive cancellation system with tokens and sources
- **Promise Combinators**: `all()`, `race()`, `any()`, `allSettled()` for complex async patterns
- **Concurrency Control**: Pool execution with configurable concurrency limits
- **Retry Mechanisms**: Built-in retry with exponential backoff
- **Utility Functions**: Debouncing, throttling, timeouts, and more
- **Type Safe**: Full PHP generics support with proper type annotations

## Requirements

- PHP 8.4 or higher
- Fiber support (available in PHP 8.1+)
- Optional: PCNTL extension for signal-based cancellation

## Installation

```bash
composer require elementaryframework/async
```

## Quick Start

### Basic Promise Usage

```php
use ElementaryFramework\Core\Async\Async;

// Create a resolved promise
$promise = Async::resolve("Hello, World!");

// Create a rejected promise
$promise = Async::reject(new Exception("Something went wrong"));

// Chain operations
$promise = Async::resolve(10)
    ->then(fn($x) => $x * 2)
    ->then(fn($x) => $x + 5)
    ->catch(fn($error) => "Error: " . $error->getMessage());
```

### Asynchronous Execution

```php
use ElementaryFramework\Core\Async\Async;

Async::startEventLoop(); // Start the event loop, you should do this once at the start of your app

// Execute a function asynchronously
$promise = Async::run(function() {
    // Simulate some work
    sleep(5); // Wait 5 seconds
    return "Task completed!";
});

Async::await(); // Wait for completion

$result = $promise->unwrap();
echo $result; // "Task completed!"
```

### Delay and Timing

```php
use ElementaryFramework\Core\Async\Async;

Async::startEventLoop(); // Start the event loop

// Delay execution
Async::delay(250)->then(function() {
    echo "Executed after 250 milliseconds\n";
});

// Set timeout
$promise = Async::timeout(
    function() {
        for ($i = 0; $i < 10; $i++) {
            sleep(1);
            echo "    Long task step $i\n";
            Async::yield(); // Yield to the event loop
        }
        return "Task completed!";
    },
    5000 // 5-second timeout
);

Async::await(); // Wait for completion
```

### Promise Combinators

```php
use ElementaryFramework\Core\Async\Async;

Async::startEventLoop(); // Start the event loop, you should do this once at the start of your app

// Wait for all promises to complete
$promises = Async::all([
    Async::resolve(1),
    Async::resolve(2),
    Async::resolve(3)
]);

Async::await();
$results = $promises->unwrap(); // [1, 2, 3]
echo "All promises completed with results: " . implode(', ', $results) . "\n";

// Race multiple promises
$fastest = Async::race([
    Async::delay(1000)->then(fn() => "slow"),
    Async::delay(500)->then(fn() => "fast")
]);

Async::await();
$result = $fastest->unwrap(); // "fast"
echo "Fastest promise completed with result: $result\n";

// Wait for any promise to succeed
$any = Async::any([
    Async::reject(new Exception("Error 1")),
    Async::resolve("Success!"),
    Async::reject(new Exception("Error 2"))
]);

Async::await();
$result = $any->unwrap(); // "Success!"
echo "Any promise completed with result: $result\n";
```

### Concurrency Control

```php
use ElementaryFramework\Core\Async\Async;

Async::startEventLoop(); // Start the event loop, you should do this once at the start of your app

// Execute tasks with limited concurrency
$tasks = [];
for ($i = 0; $i < 100; $i++) {
    $tasks[] = fn() => Async::run(function () use ($i) {
        echo "Executing task $i...\n";
        $max = rand(10000, 100000);
        for ($j = 0; $j < $max; $j++) {
            usleep(1);
            Async::yield();
        }
        echo "    Task $i completed\n";
    });
}

// Execute with max 10 concurrent tasks
$promises = Async::pool($tasks, 10);

Async::await();
```

### Retry with Exponential Backoff

```php
use ElementaryFramework\Core\Async\Async;

Async::startEventLoop(); // Start the event loop, you should do this once at the start of your app

$result = Async::retry(
    operation: fn() => riskyNetworkCall(),
    maxAttempts: 5,
    baseDelay: 1000.0,    // Start with 1 second
    maxDelay: 30000.0     // Cap at 30 seconds
);

Async::await();
```

### Cancellation Support

```php
use ElementaryFramework\Core\Async\Async;
use ElementaryFramework\Core\Async\CancellationException;

Async::startEventLoop(); // Start the event loop, you should do this once at the start of your app

// Create a cancellation token source
$tokenSource = Async::createCancellationTokenSource();
$token = $tokenSource->getToken();

// Start a long-running operation
$promise = Async::run(function () use ($token) {
    for ($i = 0; $i < 1000; $i++) {
        $token->throwIfCancellationRequested();

        // Do some work
        usleep(100000); // 100ms
        Async::yield();
    }
    return "Completed all iterations";
}, $token)->catch(function (CancellationException $e) {
    echo "Operation was cancelled: " . $e->getMessage();
});

// Cancel after 2 seconds
Async::setTimeout(fn() => $tokenSource->cancel("Timeout"), 2000.0);

Async::await();
```

### Timeout Cancellation

```php
use ElementaryFramework\Core\Async\Async;

Async::startEventLoop(); // Start the event loop, you should do this once at the start of your app

// Auto-cancel after 5 seconds
$tokenSource = Async::createTimeoutTokenSource(5000.0);

$promise = Async::run(function() {
    // Long operation that might exceed 5 seconds
    return performLongOperation();
}, $tokenSource->getToken());

Async::await();
```

### Debouncing and Throttling

```php
use ElementaryFramework\Core\Async\Async;

Async::startEventLoop(); // Start the event loop, you should do this once at the start of your app

// Debounce: Execute only after calls stop for a specified time
$debouncedFunction = Async::debounce(
    fn() => echo "Debounced call executed\n",
    500 // 500ms debounce
);

// Throttle: Execute at most once per interval
$throttledFunction = Async::throttle(
    fn() => echo "Throttled call executed\n",
    1000 // Max once per second
);
```

### Error Handling

```php
use ElementaryFramework\Core\Async\Async;
use ElementaryFramework\Core\Async\PromiseState;

Async::startEventLoop(); // Start the event loop

// Handle individual promise errors
$promise = Async::run(function() {
    throw new Exception("Something went wrong");
})->catch(function($error) {
    echo "Caught error: " . $error->getMessage();
    return "Default value";
});

// Handle multiple errors with allSettled
$promises = Async::allSettled([
    Async::resolve("success"),
    Async::reject(new Exception("error 1")),
    Async::reject(new Exception("error 2"))
]);

Async::await();

$results = $promises->unwrap();
foreach ($results as $result) {
    if ($result['status'] === PromiseState::FULFILLED) {
        echo "Success: " . $result['value'] . "\n";
    } else {
        echo "Error: " . $result['reason']->getMessage() . "\n";
    }
}
```

## Advanced Usage

### Custom Deferred

```php
use ElementaryFramework\Core\Async\Deferred;

function fetchDataAsync(): PromiseInterface {
    $deferred = new Deferred();

    // Simulate async operation
    Async::setTimeout(function() use ($deferred) {
        try {
            $data = fetchDataFromAPI();
            $deferred->resolve($data);
        } catch (Exception $e) {
            $deferred->reject($e);
        }
    }, 1.0);

    return $deferred->promise();
}
```

### Signal-Based Cancellation

```php
use ElementaryFramework\Core\Async\Async;
use ElementaryFramework\Core\Async\CancellationTokenSource;

Async::startEventLoop(); // Start the event loop, you should do this once at the start of your app

if (Async::supportsPCNTL()) {
    $tokenSource = CancellationTokenSource::withSignal(SIGINT);

    $promise = Async::run(function () {
        // Long-running operation
        while (true) {
            pcntl_signal_dispatch();
            usleep(100000);
            echo ".";
            Async::yield();
        }
    }, $tokenSource->getToken())->catch(function ($e) {
        echo "\nOperation cancelled: {$e->getMessage()}\n";
        exit(0);
    });

    // Press Ctrl+C to cancel
    Async::await();
}
```

### Combining Cancellation Tokens

```php
use ElementaryFramework\Core\Async\Async;

$timeoutToken = Async::createTimeoutTokenSource(30000)->getToken();
$manualToken = Async::createCancellationTokenSource()->getToken();

$combinedToken = Async::combineCancellationTokens($timeoutToken, $manualToken);

$promise = Async::run(function() {
    // Operation that can be cancelled by timeout OR manual intervention
    return performOperation();
}, $combinedToken);
```

## Function-Level API

For convenience, the library also provides global functions:

```php
use function ElementaryFramework\Core\Async\{all,async,await,delay};

// These are equivalent to the Async:: static methods
$promise = async(fn() => "Hello"); // Async::run
$delayed = delay(1.0); // Async::delay
$combined = all([$promise, $delayed]); // Async::all
await(); // Async::await
```

## Testing

```bash
# Run tests
composer test

# Run static analysis
composer analyze

# Run both
composer check
```

## License

This library is released under the MIT License. See LICENSE file for details.

## Contributing

Contributions are welcome! Please see CONTRIBUTING.md for guidelines.

## Changelog

See CHANGELOG.md for version history and changes.
