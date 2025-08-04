# Tenant-Aware Services

The Multi-Tenant Bundle provides several tenant-aware services that automatically adapt their behavior based on the current tenant context. These services ensure proper isolation and configuration per tenant.

## 📨 Mailer Service

The tenant-aware mailer service allows each tenant to have its own email configuration, including SMTP settings, sender information, and reply-to addresses.

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    mailer:
        enabled: true
```

### Usage

#### Basic Usage

```php
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Symfony\Component\Mime\Email;

class EmailService
{
    public function __construct(
        private TenantAwareMailer $mailer
    ) {}

    public function sendWelcomeEmail(string $to): void
    {
        $email = (new Email())
            ->to($to)
            ->subject('Welcome!')
            ->text('Welcome to our platform!');

        // The mailer will automatically use tenant-specific settings
        $this->mailer->send($email);
    }
}
```

#### Tenant Settings

Configure tenant-specific mailer settings using the tenant settings manager:

```php
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class TenantConfigurationService
{
    public function __construct(
        private TenantSettingsManager $settingsManager
    ) {}

    public function configureMailer(string $dsn, string $fromEmail, string $senderName): void
    {
        $this->settingsManager->set('mailer_dsn', $dsn);
        $this->settingsManager->set('email_from', $fromEmail);
        $this->settingsManager->set('email_sender', $senderName);
        $this->settingsManager->set('email_reply_to', 'noreply@tenant.com');
    }
}
```

#### Transport Factory

For advanced use cases, you can use the tenant transport factory directly:

```yaml
# config/packages/mailer.yaml
framework:
    mailer:
        dsn: 'tenant://default'  # Uses tenant-specific DSN
```

### Available Settings

| Setting Key | Description | Example |
|-------------|-------------|---------|
| `mailer_dsn` | SMTP DSN for the tenant | `smtp://user:pass@smtp.example.com:587` |
| `email_from` | Default from address | `noreply@tenant.com` |
| `email_sender` | Sender name | `Tenant Name` |
| `email_reply_to` | Reply-to address | `support@tenant.com` |

## 📬 Messenger Service

The tenant-aware messenger service allows each tenant to have its own message transport configuration, supporting various transport types and delays.

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    messenger:
        enabled: true
```

### Usage

#### Transport Configuration

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            tenant_async: 'tenant://default'  # Uses tenant-specific transport
```

#### Tenant Settings

```php
use Zhortein\MultiTenantBundle\Manager\TenantSettingsManager;

class TenantMessengerService
{
    public function __construct(
        private TenantSettingsManager $settingsManager
    ) {}

    public function configureTransport(): void
    {
        // Configure Redis transport for this tenant
        $this->settingsManager->set('messenger_transport_dsn', 'redis://localhost:6379/messages');
        
        // Configure delay for async processing
        $this->settingsManager->set('messenger_delay', 5000); // 5 seconds
        
        // Configure specific bus
        $this->settingsManager->set('messenger_bus', 'messenger.bus.async');
    }
}
```

### Supported Transport Types

- `sync://` - Synchronous processing
- `doctrine://default` - Database transport
- `redis://localhost:6379/messages` - Redis transport
- `amqp://guest:guest@localhost:5672/%2f/messages` - RabbitMQ transport

### Available Settings

| Setting Key | Description | Example |
|-------------|-------------|---------|
| `messenger_transport_dsn` | Transport DSN | `redis://localhost:6379/messages` |
| `messenger_bus` | Bus service name | `messenger.bus.async` |
| `messenger_delay` | Default delay in milliseconds | `5000` |
| `messenger_delay_{transport}` | Transport-specific delay | `messenger_delay_async: 10000` |

## 🗂️ Storage Service

The tenant-aware storage service provides isolated file storage for each tenant, supporting both local filesystem and cloud storage.

### Configuration

```yaml
# config/packages/zhortein_multi_tenant.yaml
zhortein_multi_tenant:
    storage:
        enabled: true
        type: local  # or 's3'
        local:
            base_path: '%kernel.project_dir%/var/storage'
            base_url: '/uploads'
        s3:
            bucket: 'my-tenant-bucket'
            region: 'us-east-1'
            base_url: 'https://cdn.example.com'
```

### Usage

#### Basic File Operations

```php
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class DocumentService
{
    public function __construct(
        private TenantFileStorageInterface $storage
    ) {}

    public function uploadDocument(UploadedFile $file): string
    {
        // File will be stored in tenant-specific directory
        $path = $this->storage->uploadFile($file, 'documents/' . $file->getClientOriginalName());
        
        return $this->storage->getUrl($path);
    }

    public function deleteDocument(string $path): void
    {
        $this->storage->delete($path);
    }

    public function listDocuments(): array
    {
        return $this->storage->listFiles('documents');
    }
}
```

#### File Path Structure

Files are automatically organized by tenant:

```
/var/storage/
├── tenant-1/
│   ├── documents/
│   │   ├── contract.pdf
│   │   └── invoice.pdf
│   └── images/
│       └── logo.png
└── tenant-2/
    ├── documents/
    │   └── agreement.pdf
    └── uploads/
        └── avatar.jpg
```

### Integration with VichUploaderBundle

```php
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[Vich\Uploadable]
class Document
{
    #[Vich\UploadableField(mapping: 'tenant_documents', fileNameProperty: 'fileName')]
    private ?File $file = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $fileName = null;
}
```

```yaml
# config/packages/vich_uploader.yaml
vich_uploader:
    mappings:
        tenant_documents:
            uri_prefix: /uploads
            upload_destination: '%kernel.project_dir%/var/storage'
            namer: Vich\UploaderBundle\Naming\SmartUniqueNamer
```

## 🗄️ Database Features

### Automatic Tenant Filtering

The bundle automatically filters database queries to only return data for the current tenant.

#### Using the Trait

```php
use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityTrait;

#[ORM\Entity]
class Product implements TenantOwnedEntityInterface
{
    use TenantOwnedEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // ... other properties
}
```

#### Manual Implementation

```php
use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;

#[ORM\Entity]
class Order implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TenantInterface::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?TenantInterface $tenant = null;

    public function getTenant(): ?TenantInterface
    {
        return $this->tenant;
    }

    public function setTenant(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }
}
```

### Automatic Tenant Assignment

The bundle automatically assigns the current tenant to new entities:

```php
// The tenant will be automatically set when persisting
$product = new Product();
$product->setName('New Product');

$entityManager->persist($product); // Tenant is automatically set
$entityManager->flush();
```

### Tenant-Specific Migrations

Run migrations for specific tenants:

```bash
# Migrate all tenants
php bin/console tenant:migrate

# Migrate specific tenant
php bin/console tenant:migrate --tenant=acme

# Dry run to see SQL
php bin/console tenant:migrate --dry-run
```

### Tenant-Specific Fixtures

Load fixtures for specific tenants:

```bash
# Load fixtures for all tenants
php bin/console tenant:fixtures:load

# Load fixtures for specific tenant
php bin/console tenant:fixtures:load --tenant=acme

# Load specific fixture groups
php bin/console tenant:fixtures:load --group=demo --group=test
```

## 🔧 Advanced Configuration

### Custom Storage Implementation

```php
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

class CustomCloudStorage implements TenantFileStorageInterface
{
    // Implement interface methods for your cloud provider
}
```

```yaml
# config/services.yaml
services:
    App\Storage\CustomCloudStorage:
        tags: ['zhortein_multi_tenant.storage']

zhortein_multi_tenant:
    storage:
        type: custom
```

### Event Listeners

Listen to tenant-specific events:

```php
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zhortein\MultiTenantBundle\Event\TenantDatabaseSwitchEvent;

class TenantEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            TenantDatabaseSwitchEvent::class => 'onTenantDatabaseSwitch',
        ];
    }

    public function onTenantDatabaseSwitch(TenantDatabaseSwitchEvent $event): void
    {
        $tenant = $event->getTenant();
        // Perform tenant-specific setup
    }
}
```

## 🧪 Testing

### Unit Testing

```php
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Storage\TenantFileStorageInterface;

class DocumentServiceTest extends TestCase
{
    public function testUploadDocument(): void
    {
        $storage = $this->createMock(TenantFileStorageInterface::class);
        $storage->expects($this->once())
            ->method('uploadFile')
            ->willReturn('tenant-1/documents/test.pdf');

        $service = new DocumentService($storage);
        // ... test implementation
    }
}
```

### Integration Testing

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TenantAwareServiceTest extends KernelTestCase
{
    public function testTenantIsolation(): void
    {
        self::bootKernel();
        
        $tenantContext = self::getContainer()->get('zhortein_multi_tenant.context');
        $storage = self::getContainer()->get(TenantFileStorageInterface::class);
        
        // Test with different tenants
        // ... test implementation
    }
}
```

## 📚 Best Practices

1. **Always use interfaces** - Depend on `TenantFileStorageInterface`, not concrete implementations
2. **Handle tenant context** - Check if tenant is available before performing operations
3. **Use settings manager** - Store tenant-specific configuration in the settings manager
4. **Test isolation** - Ensure proper tenant isolation in your tests
5. **Monitor performance** - Be aware of the overhead of tenant-specific operations
6. **Backup strategies** - Implement tenant-aware backup and restore procedures

## 🔍 Troubleshooting

### Common Issues

1. **Files not isolated**: Ensure tenant context is properly set
2. **Email not sent**: Check tenant mailer DSN configuration
3. **Messages not processed**: Verify messenger transport configuration
4. **Database queries not filtered**: Ensure entities implement `TenantOwnedEntityInterface`

### Debug Commands

```bash
# Check tenant configuration
php bin/console debug:container zhortein_multi_tenant

# List tenant settings
php bin/console tenant:settings:list --tenant=acme

# Clear tenant cache
php bin/console tenant:cache:clear
```