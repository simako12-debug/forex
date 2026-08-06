---
title: Sharry PHP Code Style
description: Comprehensive PHP code style and quality standards for Sharry projects
paths:
  - "**/*.php"
---

# Sharry PHP Code Style

This document defines PHP code style standards for Sharry. **These rules apply ONLY to PHP files (`.php`)**.

These standards must be followed in all PHP code submissions. For other languages (JavaScript, TypeScript, YAML, etc.), follow their respective code style guidelines.

## File Header & Declarations

- **Required**: All PHP files MUST start with `declare(strict_types=1);` on the first line
- **Format**: No blank lines before the declare statement
- **Namespace**: All files must declare a namespace
- **Example**:
  ```php
  <?php
  declare(strict_types=1);

  namespace Sharry\Workplace\ModuleName;
  ```

## Namespaces & Use Statements

- **Alphabetical Order**: All `use` statements must be alphabetically sorted
- **Single Import Per Line**: No group imports (e.g., `use Foo\{Bar, Baz}`)
- **No Leading Backslash**: Use statements must not start with `\`
- **Remove Unused**: All unused imports must be removed from the file
- **Same Namespace**: Avoid importing from the same namespace you're in
- **Format Example**:
  ```php
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Support\Collection;
  use Sharry\Custom\McKinsey\Models\Destination;
  ```

## Type Declarations & Hints

### Type Hints

- **Parameters**: All parameters must have type hints
- **Return Types**: All methods must have return type declarations
- **Properties**: All properties must have type hints (including readonly when applicable)
- **Prefer Interfaces**: Use interfaces as type hints instead of concrete implementations whenever possible
- **Format**:
  ```php
  public function process(string $name, UuidInterface $id): void
  {
      $this->validateInput($name, $id);
  }
  ```

### Interfaces Over Implementations

- **Dependency Injection**: Type-hint against interfaces, not concrete classes
- **Loose Coupling**: Allows swapping implementations without changing code
- **Testing**: Easy to mock interface implementations for tests
- **SOLID Principle**: Follows Dependency Inversion Principle

**Examples**:

```php
// ✅ PREFERRED: Use interface
interface RepositoryInterface
{
    public function getById(UuidInterface $id): null|Model;
}

final readonly class Service
{
    public function __construct(
        private RepositoryInterface $repository,  // Interface type hint
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function process(CollectionInterface $items): void
    {
        // Implementation
    }
}

// ❌ AVOID: Concrete implementations
final readonly class Service
{
    public function __construct(
        private UserRepository $repository,  // Concrete class
        private RedisCache $cache,           // Concrete class
        private FileLogger $logger,          // Concrete class
    ) {
    }
}
```

**Common Interfaces to Use**:
- `RepositoryInterface` instead of concrete repository
- `CacheInterface` instead of `RedisCache`, `FileCache`
- `LoggerInterface` instead of specific logger
- `CollectionInterface` instead of specific collection
- `Enumerable` instead of `Collection` or `LazyCollection`
- `UuidInterface` instead of `Uuid`
- Framework contracts: `Illuminate\Contracts\*`

**Laravel Framework Contracts**:
```php
// ✅ PREFERRED: Use contracts (interfaces)
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Mail\Mailable;

final readonly class NotificationService
{
    public function __construct(
        private CacheRepository $cache,
        private Queue $queue,
    ) {
    }
}

// ❌ AVOID: Concrete implementations
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\Queue;

final readonly class NotificationService
{
    public function __construct(
        private Cache $cache,  // Facade, not contract
        private Queue $queue,
    ) {
    }
}
```

### Nullable & Optional Types

- **Nullable**: Use `null|Type` for properties/parameters that can be null (never use `?Type` shorthand)
- **Explicit**: Always be explicit with union types to avoid confusion
- **Consistency**: All nullable types must use the full `null|Type` syntax
- **Example**:
  ```php
  // ✅ CORRECT: Explicit union type
  public function getUserById(UuidInterface $id): null|User
  {
      return User::query()->find($id);
  }

  // ❌ WRONG: Using shorthand (never use ?)
  public function getUserById(UuidInterface $id): ?User
  {
      return User::query()->find($id);
  }
  ```

### Type Hint Spacing

- **Before/After Parameters**: Single space before the colon in return types
- **After Cast**: Single space after type casts (e.g., `(string) $value`)
- **No Space After Not Operator**: Use `!$variable`, not `! $variable`

## Classes & Inheritance

### Class Modifiers

- **Default**: Use `final readonly` for ALL classes unless there's a specific reason NOT to
- **Final**: Prevents accidental inheritance and makes inheritance intent explicit
- **Readonly**: Prevents modification after construction, making objects immutable and thread-safe
- **When to Use**: Repositories, Services, Controllers, DTOs, Actions, Value Objects - essentially everything except:
  - Abstract base classes (use `abstract`)
  - Eloquent Models (inherit from Model)
  - Laravel exceptions (inherit from Exception)
  - Base classes designed for inheritance

**If a class needs to be mocked in tests**, that's a sign it should implement an **interface**:
- Create an interface for the class contract
- Make the class `final readonly` and implement the interface
- Mock the interface, not the class
- This preserves immutability while maintaining testability

**Example** - Apply final readonly by default:
  ```php
  // Good: class is final readonly, use interface for testing
  interface DestinationRepositoryInterface
  {
      public function getCountries(): Enumerable;
      public function getAccessLevels(string $destinationSystemId): Collection;
  }

  final readonly class DestinationRepository implements DestinationRepositoryInterface
  {
      public function __construct(
          private Client $client,
          private Repository $cache,
      ) {
      }

      public function getCountries(): Enumerable
      {
          // Implementation
      }

      public function getAccessLevels(string $destinationSystemId): Collection
      {
          // Implementation
      }
  }

  // In tests, mock the interface:
  $mockRepository = $this->mock(DestinationRepositoryInterface::class);
  $mockRepository->shouldReceive('getCountries')->andReturn(collect([]));

  final readonly class DestinationService
  {
      public function __construct(private DestinationRepositoryInterface $repository)
      {
      }
  }

  final readonly class CreateDestinationController
  {
      public function __invoke(CreateDestinationRequest $request): JsonResponse
      {
          // Implementation
      }
  }
  ```

**Wrong approach** - Don't skip final/readonly just for testing:
  ```php
  // ❌ Don't do this - removes immutability guarantee
  class DestinationRepository implements DestinationRepositoryInterface
  {
      // Now it can be extended or modified - bad!
  }
  ```

**Exception** - Don't use final/readonly on:
  ```php
  // Base classes for inheritance
  abstract class BaseRepository
  {
      // Abstract methods and shared logic
  }

  // Eloquent models
  class Destination extends Model
  {
      // Model definition
  }

  // Laravel framework classes
  class CustomException extends Exception
  {
      // Exception handling
  }
  ```

### Visibility

- **Explicit Visibility**: All properties and methods must have explicit visibility (public, protected, private)
- **Constants**: Class constants must have explicit visibility
- **Private Methods**: Use for internal implementation logic

### Class Constants

- **UPPER_CASE**: All constant names must be UPPER_CASE with underscores
- **Visibility**: Must have explicit visibility declaration

## PHPDoc Comments

### Required Documentation

- **Magic Properties**: Eloquent models and classes with magic properties must document them with `@property` or `@property-read` annotations for IDE autocompletion and type checking
- **Regular Properties**: Actual class properties are typed directly via property type hints, not PHPDoc
- **Methods**: Non-trivial methods should have PHPDoc with parameter and return documentation
- **Generic Types**: Collections and relationships must use generic syntax

### Forbidden Annotations

The following annotations are NOT allowed and will be flagged as errors:
- `@author` - Use git history for authorship
- `@created` - Use git history for creation dates
- `@version` - Use git tags
- `@package` - Use namespace
- `@copyright` - Use git history
- `@license` - Define at project level
- `@coversDefaultClass` - Use `#[CoversClass()]` attribute
- `@covers` - Use `#[CoversClass()]` attribute
- `@dataProvider` - Use `#[DataProvider()]` attribute

### PHPDoc Format

**Magic Properties Example** (Eloquent Model with virtual properties):

```php
/**
 * @property-read UuidInterface $id
 * @property string $name
 * @property DestinationTypeEnum $type
 * @property null|string $region
 * @property-read Collection<int,Access> $access
 *
 * @method static DestinationBuilder<Destination>|Destination query()
 * @method static DestinationFactory factory()
 */
class Destination extends Model
{
    /**
     * @return Enumerable<int,CountryData>
     */
    public function getCountries(): Enumerable
    {
        // Implementation
    }

    /**
     * @return HasOne<File, $this>
     */
    public function image(): HasOne
    {
        return $this->hasOne(File::class, 'target_id', 'id');
    }
}
```

**Regular Class Example** (typed properties, no @property needed):

```php
final readonly class Repository
{
    public function __construct(
        private Client $client,
        private Cache $cache,
    ) {
    }

    /**
     * @return Enumerable<int,CountryData>
     */
    public function getCountries(): Enumerable
    {
        // Implementation
    }
}
```

## Comments & Documentation

### Forbidden Comment Patterns

The following comment patterns are automatically removed by the style checker:
- `Created by PhpStorm.`
- `Constructor.`
- `User: ...`
- `Date: ...`
- `Time: ...`
- Empty comments (just `//`)

### Inline Comments

- **Avoid Useless Comments**: Remove comments that don't add value
- **Meaningful Comments**: Only add comments for non-obvious logic
- **English Only**: All comments must be in English

### Docblock Comments

- **Empty Docblocks**: Not allowed (remove if no content)
- **Inline Docblocks**: Must contain meaningful documentation

## Arrays

### Array Brackets

- **No Spaces**: `$array['key']` not `$array [ 'key' ]`
- **Short Syntax**: Use `[]` not `array()`

### Trailing Commas

- **Multi-line Arrays**: Must have trailing comma on last element
- **Single-line Arrays**: No trailing comma
- **Example**:
  ```php
  $config = [
      'name' => 'Test',
      'type' => 'admin',  // <- trailing comma required
  ];
  ```

## Operators & Control Structures

### Operator Spacing

- **Equal Operators**: Must use strict comparison (`===`, `!==`) not loose (`==`, `!=`)
- **Space Around**: Single space around binary operators
- **Example**: `if ($count === 0) { ... }` not `if ($count == 0) { ... }`

### Comparison (Yoda)

- **No Yoda Comparisons**: Write `if ($foo === 'bar')` not `if ('bar' === $foo)`
- **Variable First**: Always put the variable on the left side of comparison

### Control Structures

- **Lowercase Keywords**: `if`, `else`, `foreach`, `while`, `for`, `switch`, etc. must be lowercase
- **Spacing**: One space after control structure keyword
- **Braces**: Opening brace on same line, not next line
- **Example**:
  ```php
  if ($condition) {
      // code
  } elseif ($other) {
      // code
  } else {
      // code
  }
  ```

### Inline Control Structures

- **Forbidden**: No inline control structures without braces
- **Required**:
  ```php
  if ($condition) {
      return $value;
  }
  ```
- **Not Allowed**: `if ($condition) return $value;`

### Assignment in Conditions

- **Forbidden**: Cannot assign values inside conditional statements
- **Wrong**: `if ($x = getValue()) { ... }`
- **Correct**: `$x = getValue(); if ($x) { ... }`

### Jump Statement Spacing

- **Before**: One blank line before `return`, `break`, `continue`, `throw`
- **After**: One blank line after these statements if more code follows
- **Exception**: Last statement in a block doesn't need spacing

### Block Control Structure Spacing

- **Spacing Before**: One blank line before control structures (if, do, while, for, foreach, switch, try)
- **Spacing After**: One blank line after closing brace if more code follows
- **Exception**: Last statement in a block doesn't need spacing

## Strings & Functions

### String Quotes

- **Double Quotes**: For strings containing variables or complex content
- **Single Quotes**: For simple literal strings
- **Avoid Variable Interpolation**: Use concatenation or string templates
- **Example**:
  ```php
  $message = 'Hello'; // simple string
  $greeting = "Hello {$name}"; // with variable
  $url = "https://example.com/path?id=" . $id; // concatenation
  ```

### PHP Functions

- **Lowercase**: All PHP function calls must be lowercase
- **Example**: `strlen()` not `StrLen()`, `array_map()` not `array_Map()`

### Constants

- **Lowercase**: Built-in PHP constants must be lowercase (e.g., `true`, `false`, `null`)

### Language Constructs

- **With Parentheses**: `echo()`, `print()`, `return()`, etc. must be written with parentheses
- **Example**: `return($value);` or `echo($message);`

## Arrow Functions

Arrow functions must follow this format:
- **No space** after `fn` keyword
- **One space** before and after `=>`
- **Multi-line allowed** with proper indentation

```php
// Single line
$mapped = array_map(fn(int $x): int => $x * 2, $items);

// Multi-line
$result = collect($items)->map(
    fn(Item $item): ProcessedItem =>
        ProcessItem::from($item)
);
```

## Lines & Formatting

### Line Length

- **Default**: 120 characters (enforced by default)
- **Exception**: Migration files are excluded from line length checks
- **Handling Long Lines**: Break into multiple lines using proper indentation

### Line Endings

- **Unix Format**: LF only (not CRLF)
- **Consistent**: All files must use Unix line endings

### Duplicate Spaces

- **Remove**: All duplicate spaces in code must be removed
- **Single Space**: Use single spaces for alignment/indentation

### Indentation

- **Four Spaces**: Use 4 spaces per indentation level (not tabs)
- **Alignment**: Use spaces for alignment, not tabs

## Specific Code Patterns

### Enum Usage

All enums must use backed values:
```php
enum DestinationTypeEnum: string
{
    case COUNTRY = 'country';
    case OFFICE = 'office';
    case CITY = 'city';
}
```

### Named Arguments

Use named arguments for clarity in method calls:
```php
// Good
$this->belongsToMany(
    related: Access::class,
    table: 'mckinsey_destination_access',
)->withPivot(['from', 'to']);

// Avoid positional arguments
$this->belongsToMany(Access::class, 'mckinsey_destination_access');
```

### Entity-Specific Classes - Avoid Redundant Names

When a class is dedicated to a specific entity (like repositories, services, controllers), **the entity name is implicit in the class name**, so don't repeat it in method names:

**Repository Example**:
```php
// ✅ CORRECT: Entity is implicit in UserRepository
final readonly class UserRepository implements UserRepositoryInterface
{
    public function list(): Collection
    {
        return User::query()->get();
    }

    public function get(UuidInterface $id): null|User
    {
        return User::query()->find($id);
    }

    public function create(array $data): User
    {
        return User::query()->create($data);
    }

    public function update(UuidInterface $id, array $data): User
    {
        $user = $this->get($id);
        $user->update($data);
        return $user;
    }

    public function delete(UuidInterface $id): void
    {
        $this->get($id)?->delete();
    }
}

// Usage is clear due to class name:
$user = $userRepository->get($id);  // Obviously getting a User
$users = $userRepository->list();    // Obviously listing Users

// ❌ WRONG: Redundant entity names
final readonly class UserRepository implements UserRepositoryInterface
{
    public function listUsers(): Collection { }      // Redundant "Users"
    public function getUser(UuidInterface $id): null|User { }  // Redundant "User"
    public function createUser(array $data): User { }  // Redundant "User"
    public function updateUser(UuidInterface $id, array $data): User { }  // Redundant "User"
    public function deleteUser(UuidInterface $id): void { }  // Redundant "User"
}

// Usage becomes repetitive:
$user = $userRepository->getUser($id);      // "User" mentioned twice
$users = $userRepository->listUsers();      // "Users" mentioned twice
```

**Service Example**:
```php
// ✅ CORRECT: Entity is implicit in UserRegistrationService
final readonly class UserRegistrationService
{
    public function register(string $email, string $password): User
    {
        // Implementation
    }

    public function activate(UuidInterface $userId): void
    {
        // Implementation
    }

    public function deactivate(UuidInterface $userId): void
    {
        // Implementation
    }
}

// ❌ WRONG: Redundant service context
final readonly class UserRegistrationService
{
    public function registerUser(string $email, string $password): User { }  // Redundant
    public function activateUser(UuidInterface $userId): void { }             // Redundant
    public function deactivateUser(UuidInterface $userId): void { }           // Redundant
}
```

**Controller Example**:
```php
// ✅ CORRECT: Entity is implicit in UserController
final readonly class UserController
{
    public function index(): JsonResponse
    {
        // List all users
    }

    public function show(UuidInterface $id): JsonResponse
    {
        // Show single user
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        // Create user
    }

    public function update(UuidInterface $id, UpdateUserRequest $request): JsonResponse
    {
        // Update user
    }

    public function destroy(UuidInterface $id): JsonResponse
    {
        // Delete user
    }
}

// ❌ WRONG: Redundant entity names
final readonly class UserController
{
    public function getUsers(): JsonResponse { }           // Redundant
    public function getUser(UuidInterface $id): JsonResponse { }    // Redundant
    public function createUser(CreateUserRequest $request): JsonResponse { }  // Redundant
}
```

**Rule**:
- The entity name should appear in the **class name** (UserRepository, UserService, UserController)
- Method names should be **generic and concise**: `get()`, `list()`, `create()`, `update()`, `delete()`, `show()`, `store()`, `destroy()`
- **Exception**: Use specific names when the class handles **multiple entity types** (e.g., a generic repository base class or a multi-entity service)

### Constructor Properties & Dependency Injection

Use constructor property promotion with **interface type hints**:

```php
// ✅ PREFERRED: Use interfaces, constructor property promotion
final readonly class Service
{
    public function __construct(
        private RepositoryInterface $repository,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }
}

// ⚠️ AVOID: Concrete implementations
final readonly class Service
{
    public function __construct(
        private UserRepository $repository,      // Concrete class
        private RedisCache $cache,               // Concrete class
        private FileLogger $logger,              // Concrete class
    ) {
    }
}

// ❌ AVOID: Old property assignment style
final readonly class Service
{
    private RepositoryInterface $repository;
    private CacheInterface $cache;

    public function __construct(RepositoryInterface $repository, CacheInterface $cache)
    {
        $this->repository = $repository;
        $this->cache = $cache;
    }
}
```

**Rules**:
- Always use constructor property promotion (`private Type $property` in constructor)
- Type-hint ALL injected dependencies
- **Prefer interfaces** over concrete implementations
- Use `private readonly` for injected dependencies
- Never inject concrete implementations when an interface exists

### Dependency Injection Best Practices

Always design classes to depend on **abstractions (interfaces)**, not concrete implementations:

```php
// ✅ Correct: Depends on abstractions
interface UserRepositoryInterface
{
    public function getById(UuidInterface $id): null|User;
    public function save(User $user): void;
}

interface EmailServiceInterface
{
    public function send(string $to, string $subject, string $body): void;
}

final readonly class UserRegistrationService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EmailServiceInterface $emailService,
    ) {
    }

    public function register(string $email, string $password): User
    {
        $user = new User($email, $password);
        $this->userRepository->save($user);
        $this->emailService->send($email, 'Welcome', 'Thanks for joining!');
        return $user;
    }
}

// ✅ Easy to test: mock the interfaces
$userRepo = $this->mock(UserRepositoryInterface::class);
$emailService = $this->mock(EmailServiceInterface::class);
$service = new UserRegistrationService($userRepo, $emailService);

// ❌ Wrong: Depends on concrete implementations
final readonly class UserRegistrationService
{
    public function __construct(
        private UserRepository $userRepository,        // Concrete
        private SmtpEmailService $emailService,        // Concrete
    ) {
    }
}
```

**Benefits**:
- **Testability**: Easy to mock interfaces for testing
- **Flexibility**: Swap implementations without changing code
- **Loose Coupling**: Classes don't depend on specific implementations
- **SOLID Compliance**: Follows Dependency Inversion Principle

## Test Classes

### CoversClass Attribute (Required)

- **Every Test Class**: Must have the `#[CoversClass()]` attribute on the class declaration
- **Single Class**: The attribute should specify the single class being tested
- **Purpose**: Provides code coverage tracking and makes test intent explicit
- **Example**:
  ```php
  #[CoversClass(DestinationRepository::class)]
  final class DestinationRepositoryTest extends TestCase
  {
      public function testGetCountries(): void
      {
          // Test implementation
      }

      public function testGetAccessLevels(): void
      {
          // Test implementation
      }
  }
  ```

### Test Method Naming

- **Format**: `test<MethodNameToTest><OptionalSubcaseDescription>`
- **Method Name**: Match the actual method being tested (camelCase)
- **Subcase**: Optional descriptive suffix explaining the specific scenario/assertion
- **Purpose**: The test method name should clearly mirror the method under test plus the specific aspect being tested

**Examples**:
  ```php
  #[CoversClass(DestinationRepository::class)]
  final class DestinationRepositoryTest extends TestCase
  {
      // Testing getCountries() method
      public function testGetCountries(): void { }
      public function testGetCountriesReturnsEnumerable(): void { }
      public function testGetCountriesWithEmptyData(): void { }
      public function testGetCountriesThrowsException(): void { }

      // Testing getAccessLevels(string $id) method
      public function testGetAccessLevels(): void { }
      public function testGetAccessLevelsByDestinationSystem(): void { }
      public function testGetAccessLevelsWithInvalidSystemId(): void { }

      // Testing process() method
      public function testProcess(): void { }
      public function testProcessSuccessfully(): void { }
      public function testProcessWithInvalidInput(): void { }
      public function testProcessSkipsDeletedRecords(): void { }
  }
  ```

### Test Class Modifiers

- **Final**: Test classes should be `final` to prevent accidental extension
- **Example**:
  ```php
  #[CoversClass(DestinationRepository::class)]
  final class DestinationRepositoryTest extends TestCase
  {
      // Test methods
  }
  ```

## Exceptions

### Throwable Types

- **Must Extend Throwable**: Exception classes must extend `Throwable` (or a class implementing it)
- **No Generic Exceptions**: Catch specific exception types, not `Exception`

### Dead Catches

- **Forbidden**: Cannot catch exceptions that are never thrown
- Must catch exceptions that are actually thrown in the code

## Goto Statements

- **Forbidden**: `goto` statements are not allowed in any code

## Type Declarations & Type Safety

### Type Priority: Native Hints Over Annotations

**Prioritize native PHP type hints** over PHPDoc annotations whenever possible:

```php
// ✅ PREFERRED: Use native type declarations
public function getName(): string
{
    return $this->name;
}

public function process(Collection $items): Enumerable
{
    return $items->map(fn(Item $item) => $item->transform());
}

// ⚠️ AVOID: Using PHPDoc when native type exists
/**
 * @return string
 */
public function getName()
{
    return $this->name;
}
```

**Use PHPDoc annotations ONLY for complex types** that cannot be expressed in native PHP:

```php
// ✅ Complex generic types that need annotation
/**
 * @return array<int,string>
 */
public function getKeysByValue(): array
{
    return ['key1' => 'value1', 'key2' => 'value2'];
}

// ✅ Collection generics
/**
 * @return Collection<int,UserData>
 */
public function getUsers(): Collection
{
    return User::query()->get()->map(fn(User $u) => UserData::from($u));
}

// ✅ Relationship generics
/**
 * @return BelongsToMany<Access, $this>
 */
public function access(): BelongsToMany
{
    return $this->belongsToMany(Access::class);
}

// ✅ Enumerable with specific type
/**
 * @return Enumerable<int,CountryData>
 */
public function getCountries(): Enumerable
{
    return LazyCollection::make(function () {
        // Implementation
    });
}

// ✅ Complex array shapes
/**
 * @return array<string,mixed>
 */
public function toArray(): array
{
    return ['id' => $this->id, 'name' => $this->name];
}
```

**Rule of thumb**:
- **Native type exists?** Use it: `string`, `int`, `bool`, `Collection`, `Enumerable`, `User`, etc.
- **Complex generic?** Use annotation: `array<int,string>`, `Collection<int,UserData>`, `BelongsToMany<Model, $this>`
- **Never repeat** what PHP can already express

**Type Safety Examples - Common Errors**:
```php
// ❌ Missing return type hint
public function getName()  // <- no return type
{
    return $this->name;
}

// ❌ Missing parameter type
public function process($data)  // <- no type hint
{
    return $data->transform();
}

// ❌ Type mismatch
public function setCount(string $count): void
{
    $this->count = $count;  // ❌ Property expects int, assigned string
}

// ❌ Accessing property on nullable type
public function getName(): string
{
    return $this->user->name;  // ❌ $this->user could be null
}

// ❌ Wrong return type
public function getId(): string
{
    return $this->id;  // ❌ $this->id is UuidInterface, not string
}

// ❌ Missing complex type annotation
public function getUsers(): Collection  // ❌ What's in the Collection?
{
    return User::query()->get();
}

// ✅ CORRECT: With annotation for complexity
/**
 * @return Collection<int,User>
 */
public function getUsers(): Collection
{
    return User::query()->get();
}
```

### Type Safety Best Practices

- All method parameters must have type hints
- All methods must have return type declarations
- All properties must have type hints
- Use strict equality (`===`, `!==`) instead of loose comparison (`==`, `!=`)
- Use null safety: handle nullable types explicitly
- Document complex types that cannot be expressed in native PHP

## Exceptions & Exclusions

### Migrations

Migration files are excluded from line length checks due to schema definition complexity.

## Summary of Key Rules

| Rule | Standard | Category |
|------|----------|----------|
| Strict Types | Required at top | Type Safety |
| Type Hints | All parameters/returns | Type Safety |
| Nullable Types | Use `null\|Type`, never `?Type` | Type Safety |
| Interface Types | Prefer interfaces over implementations | Design |
| Declare Statement | No blank lines before | File Structure |
| Namespaces | Sorted alphabetically | Organization |
| PHPDoc | Magic properties & generics only | Documentation |
| Array Commas | Trailing comma in multi-line | Formatting |
| String Quotes | Single for literals, double for vars | Formatting |
| Operators | Strict `===`/`!==` | Code Style |
| Control Structures | Lowercase, proper spacing | Code Style |
| Constants | UPPER_CASE | Naming |
| Final Readonly | Default for all classes | Design |
| CoversClass | Required on all test classes | Testing |
| Constructor Promotion | Use with private readonly | Design |
| Line Length | 120 chars (except migrations) | Formatting |
| Line Endings | LF only | Formatting |
| Indentation | 4 spaces | Formatting |

---
