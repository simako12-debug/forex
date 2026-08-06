---
title: Sharry PHP Testing Standards
description: Standards for writing PHPUnit tests in Sharry backend projects
paths:
  - "**/*Test.php"
  - "**/Tests/**/*.php"
---

# Sharry PHP Testing Standards

This document defines standards for writing PHPUnit tests in Sharry backend projects. **These rules apply ONLY to PHP test files**.

## Class Requirements

- **`final`** is required on all test classes
- **`#[CoversClass(TestedClass::class)]`** is required on all test classes
- Class name = tested class name + suffix `Test`
- Namespace mirrors the tested class with `Tests` inserted after the first namespace segment (root namespace):
  - Tested: `Sharry\Microservice\Notification\Decorator\Integriti\AccessEnterDecorator`
  - Test: `Sharry\Microservice\Notification\Tests\Decorator\Integriti\AccessEnterDecoratorTest`
- Extends the module's own `TestCase` (not PHPUnit's directly)

**Example**:
```php
<?php

declare(strict_types=1);

namespace Sharry\Microservice\Notification\Tests\Decorator\Integriti;

use PHPUnit\Framework\Attributes\CoversClass;
use Sharry\Microservice\Notification\Decorator\Integriti\AccessEnterDecorator;
use Sharry\Microservice\Notification\Tests\TestCase;

#[CoversClass(AccessEnterDecorator::class)]
final class AccessEnterDecoratorTest extends TestCase
{
    // ...
}
```

## Method Naming

- Format: `test<PublicMethodName><OptionalSubcase>()`
- Every public method of the tested class must have at least one test method
- Subcase in suffix describes the scenario being tested:
  - `testFromRequest()` — happy path
  - `testFromRequestWhenCardIsExpired()` — edge case

## DataProviders

Use DataProviders when testing **the same logic with different inputs**.

- Method must be `public static` and return `Generator`
- Naming: `provide<TestedMethodName>Data`
- Each case must have a human-readable label: `yield 'description' => [params]`
- Attribute on the test method: `#[DataProvider('provideXyzData')]`
- Import: `use Generator;`

**Example**:
```php
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

public static function provideFromRequestData(): Generator
{
    yield 'valid card' => [
        'cardNumber' => '1234567890',
        'expected' => true,
    ];

    yield 'expired card' => [
        'cardNumber' => '0000000000',
        'expected' => false,
    ];
}

#[DataProvider('provideFromRequestData')]
public function testFromRequest(string $cardNumber, bool $expected): void
{
    // ...
}
```

## Test Structure (AAA Pattern)

Structure test methods using **Arrange / Act / Assert**, separated by blank lines:

```php
public function testFromRequest(): void
{
    // Arrange
    $request = $this->createRequest(['card' => '1234567890']);

    // Act
    $result = $this->decorator->fromRequest($request);

    // Assert
    assertSame('1234567890', $result->getCardNumber());
}
```

## Creating Test Subjects

Do **not** use `setUp()` to instantiate tested classes. Instead, use **private helper methods** that create the subject or auxiliary objects on demand. This keeps each test self-contained and explicit about what it is testing.

```php
private function createDecorator(?CardRepository $repository = null): AccessEnterDecorator
{
    return new AccessEnterDecorator(
        $repository ?? $this->mock(CardRepository::class),
    );
}

private function createRequest(array $data = []): Request
{
    return new Request(array_merge(['card' => '1234567890'], $data));
}
```

## Assertions

- **Always prefer `assertSame` over `assertEquals`** — `assertSame` is type-safe and checks both value and type; `assertEquals` performs loose comparison and can hide bugs
- Use `assertSame` as the default; only use `assertEquals` when you explicitly need loose comparison (rare)
- Each test method should verify **one logical scenario** — multiple assertions are fine as long as they all relate to the same outcome

## Exception Testing

Use `expectException()` and `expectExceptionMessage()` to test that the class throws correctly:

```php
public function testFromRequestWhenCardNotFound(): void
{
    // Arrange
    $repository = $this->mock(CardRepository::class);
    $repository->shouldReceive('find')->andReturn(null);

    // Assert
    $this->expectException(CardNotFoundException::class);
    $this->expectExceptionMessage('Card not found');

    // Act
    $this->createDecorator($repository)->fromRequest($this->createRequest());
}
```

Note: `expectException*` calls must come **before** the action that throws.

## No Logic in Tests

Tests must not contain `if/else`, `foreach`, ternary operators, or other control flow. If you feel the need to branch in a test, it is a signal to stop and reconsider:

- Use a DataProvider instead of looping over cases
- Split into separate test methods for each scenario
- Rethink what exactly you are asserting

## Private Methods

Never test private methods directly (e.g., via reflection). Private methods are implementation details.

- If a private method can be exercised through the class's public interface — test it that way
- If it cannot be reached through any public method — it is dead code and should be removed
- If it feels like it needs its own test — it likely belongs in a separate class

## Mocking

- Prefer unit tests with mocks
- Use Laravel `$this->mock(SomeClass::class)` when available
- Otherwise use Mockery

## Code Coverage

- Target: **100% code coverage**
- Prefer meaningful assertions (checking values, behavior)
- If a meaningful test cannot be written, a trivial assertion (e.g., `assertInstanceOf` on return type) is acceptable — only as a last resort for achieving coverage
