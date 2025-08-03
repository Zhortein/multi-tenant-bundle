# 🧱 Création des entités tenant-aware

## 1. Créer votre entité `Tenant`

L'entité `Tenant` doit :
- implémenter `TenantInterface`
- utiliser `TenantTrait`
- être référencée dans la config du bundle (`tenant_entity`)

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Entity\TenantTrait;

#[ORM\Entity]
class Tenant implements TenantInterface
{
    use TenantTrait;

    #[ORM\Column(length: 255)]
    private string $name;

    // getters/setters...
}
```

## 2. Créer une entité liée à un tenant (Post, Document, etc.)

L’entité doit :

* implémenter TenantOwnedEntityInterface
* posséder une relation ManyToOne vers votre Tenant
* être mappée avec le champ tenant

```php
namespace App\Entity;

use App\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Zhortein\MultiTenantBundle\Doctrine\TenantOwnedEntityInterface;

#[ORM\Entity]
class Post implements TenantOwnedEntityInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    // + titre, contenu...

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }
}
```

## 3. Résultat
Le bundle filtrera automatiquement toutes les entités TenantOwnedEntityInterface avec la bonne clause SQL :

```sql
WHERE tenant_id = :tenant_id
```

## 4. Activation du filtre Doctrine

> Aucune configuration manuelle nécessaire 🎉
> 
Le filtre est activé automatiquement grâce au TenantDoctrineFilterSubscriber.