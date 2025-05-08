# SyncopateBundle

A Symfony bundle for integrating with SyncopateDB, a flexible, lightweight data store with advanced query capabilities.

## Compatibility between the bundle and SyncopateDB

| Syncopate Bundle Versions | SyncopateDB Server Versions | State                  |
|:------------------------- |:--------------------------- |:----------------------:|
| 1.x                       | 0.x                         | Active development     |
| 2.x (planned)             | 1.x (planned)               | Planned future version |

## Installation

### Step 1: Install the Bundle

```bash
composer require phillarmonic/syncopate-bundle
```

### Step 2: Enable the Bundle

If you're using Symfony Flex, the bundle will be enabled automatically. Otherwise, add it to your `config/bundles.php`:

```php
return [
    // ...
    Phillarmonic\SyncopateBundle\PhillarmonicSyncopateBundle::class => ['all' => true],
];
```

### Step 3: Configure the Bundle

Create a configuration file at `config/packages/phillarmonic_syncopate.yaml`:

```yaml
phillarmonic_syncopate:
    # Required: set the base URL of your SyncopateDB instance
    base_url: 'http://localhost:8080'

    # Optional configurations with defaults
    timeout: 30
    retry_failed: false
    max_retries: 3
    retry_delay: 1000

    # Entity discovery
    entity_paths:
        - '%kernel.project_dir%/src/Entity'
    auto_create_entity_types: true

    # Caching
    cache_entity_types: true
    cache_ttl: 3600
```

## Usage

### Defining Entities

Define your entities using PHP 8 attributes:

```php
<?php

namespace App\Entity;

use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Attribute\Field;
use Phillarmonic\SyncopateBundle\Model\EntityDefinition;
use Phillarmonic\SyncopateBundle\Trait\EntityTrait;

#[Entity(name: 'product', idGenerator: EntityDefinition::ID_TYPE_UUID)]
class Product
{
    use EntityTrait; // Include the EntityTrait to add array conversion methods

    public ?string $id = null;

    #[Field(type: 'string', indexed: true, required: true)]
    public string $name;

    #[Field(type: 'string')]
    public string $description;

    #[Field(type: 'float', indexed: true)]
    public float $price;

    #[Field(type: 'integer')]
    public int $stock;

    #[Field(type: 'datetime', indexed: true)]
    public \DateTime $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }
}
```

### Entity Serialization with EntityTrait

The `EntityTrait` provides convenient methods to convert your entities to arrays with fine-grained control:

```php
// Get all fields
$allFields = $product->toArray();

// Get only specific fields
$simpleData = $product->extract(fields: ['name', 'price']);

// Get all fields except specified ones
$withoutDescription = $product->extractExcept(exclude: ['description']);

// Get fields with custom key mapping
$renamedFields = $product->toArray(
    fields: null, 
    exclude: [], 
    mapping: [
        'name' => 'productName',
        'price' => 'cost'
    ]
);
// Result: ['id' => '123', 'productName' => 'Product Name', 'cost' => 19.99, ...]
```

The `toArray()` method has three optional parameters:

- `$fields` - When provided, only these fields will be included
- `$exclude` - Fields to exclude from the result
- `$mapping` - Maps property names to custom keys in the result

This is particularly useful for API responses, where you might need to transform your internal data model to match external API conventions.

### Entity Relationships

SyncopateBundle supports entity relationships with cascade operations:

```php
<?php

namespace App\Entity;

use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Attribute\Field;
use Phillarmonic\SyncopateBundle\Attribute\Relationship;
use Phillarmonic\SyncopateBundle\Trait\EntityTrait;
use DateTimeInterface;

#[Entity]
class Post
{
    use EntityTrait;

    #[Field]
    private ?int $id = null;

    #[Field(required: true)]
    private string $title;

    #[Field(required: true)]
    private string $content;

    #[Field(type: 'datetime', required: true)]
    private DateTimeInterface $createdAt;

    // One-to-many relationship with Comment entities and cascade delete
    #[Relationship(
        targetEntity: Comment::class,
        type: Relationship::TYPE_ONE_TO_MANY,
        mappedBy: 'post',
        cascade: Relationship::CASCADE_REMOVE
    )]
    private array $comments = [];

    // ... getters and setters
}

#[Entity]
class Comment
{
    use EntityTrait;

    #[Field]
    private ?int $id = null;

    #[Field(required: true)]
    private string $content;

    #[Field(indexed: true, required: true)]
    private int $postId;

    // Many-to-one relationship with Post entity
    #[Relationship(
        targetEntity: Post::class,
        type: Relationship::TYPE_MANY_TO_ONE,
        inversedBy: 'comments'
    )]
    private ?Post $post = null;

    // ... getters and setters
}
```

Supported relationship types:

- `TYPE_ONE_TO_ONE`: Single reference in both directions
- `TYPE_ONE_TO_MANY`: Collection on one side, single reference on the other
- `TYPE_MANY_TO_ONE`: Single reference on one side, collection on the other
- `TYPE_MANY_TO_MANY`: Collections on both sides

Cascade options:

- `CASCADE_NONE`: No cascading actions (default)
- `CASCADE_REMOVE`: Automatically delete related entities when the parent is deleted

### Custom Repositories

SyncopateBundle supports entity-specific repositories that allow you to define custom query methods for each entity:

#### 1. Create a Custom Repository Class

```php
<?php

namespace App\Repository;

use App\Entity\Product;
use Phillarmonic\SyncopateBundle\Repository\EntityRepository;

class ProductRepository extends EntityRepository
{
    /**
     * Find products in a specific price range
     */
    public function findByPriceRange(float $minPrice, float $maxPrice): array
    {
        return $this->createQueryBuilder()
            ->gte(field: 'price', value: $minPrice)
            ->lte(field: 'price', value: $maxPrice)
            ->orderBy(field: 'price', direction: 'ASC')
            ->getResult();
    }

    /**
     * Find featured products
     */
    public function findFeaturedProducts(int $limit = 5): array
    {
        return $this->createQueryBuilder()
            ->eq(field: 'featured', value: true)
            ->gt(field: 'stock', value: 0)
            ->orderBy(field: 'price', direction: 'ASC')
            ->limit(limit: $limit)
            ->getResult();
    }

    /**
     * Get products formatted for API response
     */
    public function getProductsForApi(): array
    {
        $products = $this->findAll();

        return array_map(function(Product $product) {
            return $product->toArray(
                mapping: [
                    'id' => 'productId',
                    'price' => 'unitPrice',
                    'stock' => 'availableQuantity'
                ]
            );
        }, $products);
    }

    /**
     * Count products by category using optimized count API
     */
    public function countByCategory(string $category): int
    {
        return $this->createQueryBuilder()
            ->eq(field: 'category', value: $category)
            ->count();
    }
}
```

#### 2. Specify the Repository Class in Your Entity

```php
<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Attribute\Field;
use Phillarmonic\SyncopateBundle\Model\EntityDefinition;
use Phillarmonic\SyncopateBundle\Trait\EntityTrait;

#[Entity(
    name: 'product', 
    idGenerator: EntityDefinition::ID_TYPE_UUID,
    repositoryClass: ProductRepository::class
)]
class Product
{
    use EntityTrait;

    // ... property definitions
}
```

#### 3. Use Your Custom Repository Methods

```php
// In a controller or service
$repository = $this->repositoryFactory->getRepository(Product::class);

// Use custom repository methods
$featuredProducts = $repository->findFeaturedProducts(limit: 3);
$midRangeProducts = $repository->findByPriceRange(minPrice: 20, maxPrice: 50);

// Get API-formatted products
$apiProducts = $repository->getProductsForApi();

// Get count of products in a category
$electronicsCount = $repository->countByCategory('electronics');
```

### Repository Pattern

Use the repository pattern to interact with your entities:

```php
<?php

namespace App\Controller;

use App\Entity\Product;
use Phillarmonic\SyncopateBundle\Repository\EntityRepositoryFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProductController extends AbstractController
{
    private EntityRepositoryFactory $repositoryFactory;

    public function __construct(EntityRepositoryFactory $repositoryFactory)
    {
        $this->repositoryFactory = $repositoryFactory;
    }

    #[Route('/products', name: 'product_list', methods: ['GET'])]
    public function list(): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);
        $products = $repository->findAll();

        // Convert all products to arrays for JSON response
        $productsArray = array_map(fn($product) => $product->toArray(), $products);

        return $this->json($productsArray);
    }

    #[Route('/products/count', name: 'product_count', methods: ['GET'])]
    public function count(): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);
        $totalCount = $repository->count();

        return $this->json([
            'total' => $totalCount
        ]);
    }

    #[Route('/products/{id}', name: 'product_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);
        $product = $repository->find(id: $id);

        if (!$product) {
            throw $this->createNotFoundException('Product not found');
        }

        // Only include specific fields in the response
        $productData = $product->extract(fields: ['name', 'price', 'description']);

        return $this->json($productData);
    }

    #[Route('/products', name: 'product_create', methods: ['POST'])]
    public function create(): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);

        $product = new Product();
        $product->name = 'New Product';
        $product->description = 'This is a new product';
        $product->price = 19.99;
        $product->stock = 100;

        $product = $repository->create(entity: $product);

        return $this->json($product->toArray(), 201);
    }

    #[Route('/products/{id}', name: 'product_update', methods: ['PUT'])]
    public function update(string $id): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);
        $product = $repository->find(id: $id);

        if (!$product) {
            throw $this->createNotFoundException('Product not found');
        }

        $product->price = 29.99;
        $product = $repository->update(entity: $product);

        return $this->json($product->toArray());
    }

    #[Route('/products/{id}', name: 'product_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);

        // Will automatically delete related entities with CASCADE_REMOVE
        $success = $repository->deleteById(id: $id);

        return $this->json(['success' => $success]);
    }
}
```

### Using the Query Builder

For more complex queries, use the query builder:

```php
$repository = $this->repositoryFactory->getRepository(Product::class);
$queryBuilder = $repository->createQueryBuilder();

$products = $queryBuilder
    ->gt(field: 'price', value: 20)
    ->lt(field: 'price', value: 100)
    ->contains(field: 'description', value: 'awesome')
    ->orderBy(field: 'price', direction: 'DESC')
    ->limit(limit: 10)
    ->offset(offset: 0)
    ->getResult();

// Convert results to arrays with custom field mapping
$productsArray = array_map(
    fn($product) => $product->toArray(
        mapping: [
            'name' => 'productName',
            'price' => 'cost'
        ]
    ),
    $products
);
```

### Optimized Count Operations

SyncopateBundle supports efficient count operations that leverage SyncopateDB's dedicated count API, which returns only the count without retrieving all data:

```php
// Simple count of all entities
$repository = $this->repositoryFactory->getRepository(Product::class);
$totalCount = $repository->count();

// Count with query builder filters
$queryBuilder = $repository->createQueryBuilder();
$inStockCount = $queryBuilder
    ->eq(field: 'inStock', value: true)
    ->gt(field: 'price', value: 50)
    ->count();

// Count with pagination info
$queryBuilder = $repository->createQueryBuilder();
$filteredCount = $queryBuilder
    ->contains(field: 'name', value: 'gaming')
    ->count();

$pageSize = 10;
$totalPages = ceil($filteredCount / $pageSize);
```

#### Count with Join Queries

The count API also supports join operations, allowing you to count entities based on related data:

```php
// Count posts with comments from the last 7 days
$repository = $this->repositoryFactory->getRepository(Post::class);
$joinQueryBuilder = $repository->createJoinQueryBuilder();

$recentlyCommentedPostsCount = $joinQueryBuilder
    ->innerJoin(
        entityType: 'comment',
        localField: 'id',
        foreignField: 'postId',
        as: 'comments'
    )
    ->gt(field: 'comments.createdAt', value: new \DateTime('-7 days'))
    ->count();

// Count users who have purchased a specific product
$repository = $this->repositoryFactory->getRepository(User::class);
$joinQueryBuilder = $repository->createJoinQueryBuilder();

$purchaserCount = $joinQueryBuilder
    ->innerJoin(
        entityType: 'order',
        localField: 'id',
        foreignField: 'userId',
        as: 'orders'
    )
    ->innerJoin(
        entityType: 'order_item',
        localField: 'orders.id',
        foreignField: 'orderId',
        as: 'items'
    )
    ->eq(field: 'items.productId', value: $productId)
    ->count();
```

#### When to use optimized count

The optimized count API is particularly useful for:

1. **Pagination**: Calculate total pages without retrieving all entities
2. **Performance monitoring**: Check the size of result sets before executing expensive queries
3. **UI elements**: Display count badges or indicators with minimal database overhead
4. **Large datasets**: Get counts from tables with millions of records efficiently
5. **Complex joins**: Determine relationship counts without materializing all related entities

This approach significantly reduces memory usage and network traffic compared to retrieving all entities and counting them in PHP.

### Join Queries

Use join queries to fetch related entities in a single request:

```php
$repository = $this->repositoryFactory->getRepository(Post::class);
$joinQueryBuilder = $repository->createJoinQueryBuilder();

$posts = $joinQueryBuilder
    ->innerJoin(
        entityType: 'comment',
        localField: 'id',
        foreignField: 'postId',
        as: 'comments'
    )
    ->gt(field: 'comments.createdAt', value: new \DateTime('-7 days'))
    ->getJoinResult();

// Prepare posts for API response with renamed fields
$postsData = [];
foreach ($posts as $post) {
    $postData = $post->extract(fields: ['title', 'content', 'createdAt']);

    // Map comments to array with only necessary fields
    $postData['comments'] = array_map(
        fn($comment) => $comment->extract(fields: ['content']),
        $post->comments
    );

    $postsData[] = $postData;
}
```

### Direct Service Usage

You can also inject and use the `SyncopateService` directly:

```php
<?php

use Phillarmonic\SyncopateBundle\Service\SyncopateService;

class ProductService
{
    private SyncopateService $syncopateService;

    public function __construct(SyncopateService $syncopateService)
    {
        $this->syncopateService = $syncopateService;
    }

    public function getProductsByPriceRange(float $min, float $max): array
    {
        return $this->syncopateService->findBy(
            entityClass: Product::class,
            criteria: [],
            orderBy: ['price' => 'ASC']
        );
    }

    public function getProductCountByCriteria(array $criteria): int
    {
        return $this->syncopateService->count(
            entityClass: Product::class, 
            criteria: $criteria
        );
    }

    public function deleteProductWithRelations(string $id): bool
    {
        // Will automatically handle cascade delete based on relationship attributes
        return $this->syncopateService->deleteById(
            entityClass: Product::class, 
            id: $id, 
            enableCascade: true
        );
    }

    public function getProductsForApi(): array
    {
        $products = $this->getProductsByPriceRange(min: 10, max: 100);

        // Transform for API response using EntityTrait
        return array_map(
            fn($product) => $product->toArray(
                mapping: [
                    'id' => 'productId',
                    'price' => 'unitPrice',
                    'stock' => 'availableQuantity'
                ]
            ),
            $products
        );
    }
}
```

## Error Handling

SyncopateBundle provides comprehensive error handling with specific exception types and detailed error information from SyncopateDB.

### Exception Types

The bundle includes specialized exception classes for different error scenarios:

1. **SyncopateApiException** - Base exception for all SyncopateDB API errors
2. **SyncopateValidationException** - Thrown when entity validation fails
3. **SyncopateIntegrityConstraintException** - Thrown when unique constraints are violated

### Error Categories

SyncopateDB errors are organized into categories, each with specific error codes:

- **General Errors (SY001-SY099)** - General API errors, authentication, timeouts
- **Entity Type Errors (SY100-SY199)** - Issues with entity type definitions
- **Entity Errors (SY200-SY299)** - Entity validation, constraints, field issues
- **Query Errors (SY300-SY399)** - Invalid queries, filters, joins
- **Persistence Errors (SY400-SY499)** - Database storage, backup, recovery issues

### Handling Specific Errors

```php
use Phillarmonic\SyncopateBundle\Exception\SyncopateApiException;
use Phillarmonic\SyncopateBundle\Exception\SyncopateValidationException;
use Phillarmonic\SyncopateBundle\Exception\SyncopateIntegrityConstraintException;

try {
    $repository = $this->repositoryFactory->getRepository(Product::class);
    
    $product = new Product();
    $product->name = 'Test Product';
    $product->sku = 'SKU123'; // Assuming SKU has a unique constraint
    
    $repository->create($product);
} catch (SyncopateIntegrityConstraintException $e) {
    // Handle unique constraint violations
    $field = $e->getField(); // e.g., 'sku'
    $value = $e->getValue(); // e.g., 'SKU123'
    
    // Get user-friendly message
    $friendlyMessage = $e->getFriendlyMessage();
    // e.g., "The sku 'SKU123' is already in use. Please choose a different value."
    
    return $this->json([
        'error' => 'duplicate_entity',
        'message' => $friendlyMessage,
        'field' => $field
    ], 409);
} catch (SyncopateValidationException $e) {
    // Handle validation errors with field-specific messages
    $violations = $e->getViolations();
    
    return $this->json([
        'error' => 'validation_failed',
        'message' => 'Please fix the following issues:',
        'violations' => $violations
    ], 400);
} catch (SyncopateApiException $e) {
    // Get detailed error information
    $httpCode = $e->getCode();
    $dbCode = $e->getDbCode(); // e.g., 'SY209'
    $category = $e->getErrorCategory(); // e.g., 'Entity'
    
    // Check if it's a client or server error
    $isClientError = $e->isClientError();
    
    // Check for specific error types
    if ($e->isNotFoundError()) {
        return $this->json([
            'error' => 'not_found',
            'message' => 'The requested resource could not be found.'
        ], 404);
    }
    
    return $this->json([
        'error' => 'api_error',
        'message' => $e->getMessage(),
        'code' => $dbCode
    ], $httpCode);
}
```

### Common Error Scenarios

#### Entity Not Found (SY200)

```php
try {
    $product = $repository->find('non-existent-id');
} catch (SyncopateApiException $e) {
    if ($e->isErrorType('SY200')) {
        // Entity not found handling
    }
}
```

#### Unique Constraint Violation (SY209)

```php
try {
    $repository->create($product);
} catch (SyncopateIntegrityConstraintException $e) {
    // Specialized exception type with field information
    $field = $e->getField(); // The field that caused the violation
    $value = $e->getValue(); // The duplicated value
}
```

#### Validation Errors (SY203, SY206, SY207, SY208)

```php
try {
    $repository->create($invalidProduct);
} catch (SyncopateValidationException $e) {
    // Get all violations as field => message array
    $violations = $e->getViolations();
    
    // Check for a specific field error
    if ($e->hasViolation('price')) {
        $priceError = $e->getViolation('price');
    }
}
```

## Command Line Tools

The bundle provides console commands for managing entities:

```bash
# Register entity types in SyncopateDB
bin/console syncopate:register-entity-types

# Force update of entity types even if they already exist
bin/console syncopate:register-entity-types --force

# Specify additional paths to scan for entity classes
bin/console syncopate:register-entity-types --path=src/CustomEntities

# Register specific entity classes
bin/console syncopate:register-entity-types --class=App\\Entity\\Product
```

## Debug Tools

The bundle includes the `DebugHelper` class for troubleshooting common issues:

```php
use Phillarmonic\SyncopateBundle\Util\DebugHelper;

// Enable detailed debug mode
DebugHelper::enableDebug();

// Set a custom log callback
DebugHelper::setLogCallback(function($message, $context) {
    // Your custom logging implementation
    $this->logger->debug($message, $context ?? []);
});

// Check for problematic data types in an array
$issues = DebugHelper::checkArrayForProblematicTypes($data);
if (!empty($issues)) {
    foreach ($issues as $issue) {
        echo "Issue at {$issue['path']}: {$issue['issue']}\n";
    }
}

// Report memory usage
$memoryInfo = DebugHelper::getMemoryUsage();
echo "Memory used: {$memoryInfo['current']} (peak: {$memoryInfo['peak']})\n";

// Sanitize data for JSON encoding
$sanitizedData = DebugHelper::sanitizeForJson($data);
$json = DebugHelper::tryJsonEncode($sanitizedData);
```

## License

This bundle is available under the MIT License.