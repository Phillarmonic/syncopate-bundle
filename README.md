# SyncopateBundle

A Symfony bundle for integrating with SyncopateDB, a flexible, lightweight data store with advanced query capabilities.

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

#[Entity(name: 'product', idGenerator: EntityDefinition::ID_TYPE_UUID)]
class Product
{
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

        return $this->json($products);
    }

    #[Route('/products/{id}', name: 'product_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);
        $product = $repository->find($id);

        if (!$product) {
            throw $this->createNotFoundException('Product not found');
        }

        return $this->json($product);
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

        $product = $repository->create($product);

        return $this->json($product, 201);
    }

    #[Route('/products/{id}', name: 'product_update', methods: ['PUT'])]
    public function update(string $id): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);
        $product = $repository->find($id);

        if (!$product) {
            throw $this->createNotFoundException('Product not found');
        }

        $product->price = 29.99;
        $product = $repository->update($product);

        return $this->json($product);
    }

    #[Route('/products/{id}', name: 'product_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $repository = $this->repositoryFactory->getRepository(Product::class);
        $success = $repository->deleteById($id);

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
    ->gt('price', 20)
    ->lt('price', 100)
    ->contains('description', 'awesome')
    ->orderBy('price', 'DESC')
    ->limit(10)
    ->offset(0)
    ->getResult();
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
            Product::class,
            [],
            ['price' => 'ASC']
        );
    }
}
```

## License

This bundle is available under the MIT License.