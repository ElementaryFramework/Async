# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-11-15

### Added

#### Core Features
- **Promise/A+ Implementation** - Full compliance with the Promise/A+ specification for JavaScript-like promise handling in PHP
- **Fiber-Based Execution** - Leverages PHP 8.1+ Fibers for true asynchronous, non-blocking operations
- **Event Loop** - Built-in event loop for managing async operations, timers, and deferred tasks
- **Type-Safe Generics** - Full PHP generics support with proper type annotations for better IDE support and type safety

#### Promise API
- `Promise` class with `then()`, `catch()`, and `finally()` methods
- `Deferred` class for manual promise resolution/rejection control
- `PromiseInterface` for consistent promise behavior across the library
- `PromiseState` enum (PENDING, FULFILLED, REJECTED) for state management

#### Static Factory Methods
- `Async::resolve()` - Create immediately resolved promises
- `Async::reject()` - Create immediately rejected promises
- `Async::run()` - Execute functions asynchronously

#### Promise Combinators
- `Async::all()` - Wait for all promises to complete successfully
- `Async::race()` - Return the first settled promise result
- `Async::any()` - Return the first successful promise, ignore failures
- `Async::allSettled()` - Wait for all promises to settle, regardless of outcome

#### Timing and Control
- `Async::delay()` - Create promises that resolve after a specified delay
- `Async::timeout()` - Wrap operations with timeout constraints
- `Async::setTimeout()` - Schedule callbacks for future execution
- `Async::setInterval()` - Schedule recurring callbacks
- `Async::clearTimer()` - Cancel timeouts and recurring intervals

#### Cancellation Support
- `CancellationToken` and `CancellationTokenInterface` for cancellation propagation
- `CancellationTokenSource` for creating and managing cancellation tokens
- `CombinedCancellationToken` for linking multiple cancellation sources
- `Async::createCancellationTokenSource()` - Factory for cancellation token sources
- `Async::createTimeoutTokenSource()` - Auto-cancelling token source with timeout
- `Async::combineCancellationTokens()` - Combine multiple cancellation tokens
- Signal-based cancellation support via PCNTL extension (optional)

#### Concurrency Control
- `Async::pool()` - Execute tasks with configurable concurrency limits
- `Async::retry()` - Retry failed operations with exponential backoff
- `Async::debounce()` - Debounce function calls to prevent excessive execution
- `Async::throttle()` - Throttle function calls to limit execution frequency

#### Utility Functions
- `Async::yield()` - Yield control to the event loop for cooperative multitasking
- `Async::await()` - Wait for all pending async operations to complete
- `Async::startEventLoop()` - Initialize and start the event loop
- `Async::stopEventLoop()` - Gracefully stop the event loop
- `Async::supportsPCNTL()` - Check for PCNTL extension availability

#### Exception Handling
- `CancellationException` - Thrown when operations are cancelled
- `AggregateException` - Container for multiple exceptions from parallel operations
- Proper exception propagation through promise chains

#### Global Functions
- `async()` - Alias for `Async::run()`
- `await()` - Alias for `Async::await()`
- `delay()` - Alias for `Async::delay()`
- `all()` - Alias for `Async::all()`
- `race()` - Alias for `Async::race()`
- `any()` - Alias for `Async::any()`
- `allSettled()` - Alias for `Async::allSettled()`

#### Developer Experience
- Comprehensive PHPDoc annotations for IDE support
- Full test coverage with Pest PHP testing framework
- Static analysis support with PHPStan

[1.0.0]: https://github.com/ElementaryFramework/Async/releases/tag/v1.0.0
