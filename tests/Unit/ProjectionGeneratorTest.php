<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Unit;

use Darangonaut\DoctrineProjections\Exceptions\DuplicateProjectionName;
use Darangonaut\DoctrineProjections\Exceptions\UnsupportedMapping;
use Darangonaut\DoctrineProjections\Generation\EntityFilter;
use Darangonaut\DoctrineProjections\Generation\ProjectionGenerator;
use Darangonaut\DoctrineProjections\Generation\RenderedProjection;
use Darangonaut\DoctrineProjections\Tests\EntityManagerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The generator across relations and keys that a typical schema does not
 * have. Every case here either crashed the generator or made it emit a
 * silently wrong model at some point:
 *
 *   - the inverse side of a OneToOne has no joinColumns
 *   - a composite key was reduced to its first column
 *   - a string key got an implicit int cast
 *   - a relation class name colliding with a model produced a TypeError
 *
 * No database is involved: rendering is a pure metadata transformation.
 */
final class ProjectionGeneratorTest extends TestCase
{
    private const string NAMESPACE = 'App\\Models\\Projections';

    /** @return array<string, RenderedProjection> */
    private function generate(string $fixtureDir = 'Entities', ?EntityFilter $filter = null): array
    {
        return (new ProjectionGenerator(
            EntityManagerFactory::forFixtures($fixtureDir),
            self::NAMESPACE,
            $filter ?? EntityFilter::everything(),
        ))->generate();
    }

    private function code(string $class, string $fixtureDir = 'Entities'): string
    {
        return $this->generate($fixtureDir)[$class]->code;
    }

    #[Test]
    public function one_to_one_owning_side_becomes_belongs_to(): void
    {
        $code = $this->code('Profile');

        self::assertStringContainsString("return \$this->belongsTo(Account::class, 'account_id');", $code);
        self::assertStringContainsString('@property int $account_id', $code);
    }

    #[Test]
    public function one_to_one_inverse_side_becomes_has_one(): void
    {
        $code = $this->code('Account');

        self::assertStringContainsString("return \$this->hasOne(Profile::class, 'account_id');", $code);
        self::assertStringContainsString('@property Profile|null $profile', $code);
        self::assertStringNotContainsString('belongsTo(Profile::class', $code);
    }

    #[Test]
    public function composite_key_is_refused_not_silently_truncated(): void
    {
        $projection = $this->generate()['Enrollment'];

        self::assertStringContainsString('protected $primaryKey = null;', $projection->code);
        self::assertStringContainsString('public $incrementing = false;', $projection->code);
        self::assertStringNotContainsString("primaryKey = 'student_id'", $projection->code);

        self::assertCount(1, $projection->warnings);
        self::assertStringContainsString('composite key', $projection->warnings[0]);
    }

    #[Test]
    public function string_key_emits_key_type_and_disables_incrementing(): void
    {
        $code = $this->code('Document');

        self::assertStringContainsString("protected \$primaryKey = 'uuid';", $code);
        self::assertStringContainsString("protected \$keyType = 'string';", $code);
        self::assertStringContainsString('public $incrementing = false;', $code);
    }

    #[Test]
    public function integer_identity_key_stays_on_eloquent_defaults(): void
    {
        $code = $this->code('Profile');

        self::assertStringNotContainsString('$keyType', $code);
        self::assertStringNotContainsString('$incrementing', $code);
    }

    #[Test]
    public function self_referencing_relation_targets_its_own_class(): void
    {
        $code = $this->code('Document');

        self::assertStringContainsString("return \$this->belongsTo(Document::class, 'parent_uuid');", $code);
        self::assertStringContainsString('@property int|null $parent_uuid', $code);
        self::assertStringContainsString('@property Document|null $parent', $code);
    }

    /**
     * An entity named HasMany takes that short name in the projection
     * namespace, so the relation class must be fully qualified — otherwise
     * the return type resolves to the projection and the first call throws
     * a TypeError.
     */
    #[Test]
    public function relation_class_colliding_with_a_model_falls_back_to_fqcn(): void
    {
        $code = $this->code('Shelf', 'Collide');

        self::assertStringContainsString(
            'public function items(): \\Illuminate\\Database\\Eloquent\\Relations\\HasMany',
            $code,
        );
        // the method name still comes from the bare type
        self::assertStringContainsString('return $this->hasMany(HasMany::class,', $code);
        self::assertStringNotContainsString('use Illuminate\\Database\\Eloquent\\Relations\\HasMany;', $code);
    }

    #[Test]
    public function entities_sharing_a_short_name_are_refused(): void
    {
        $this->expectException(DuplicateProjectionName::class);
        $this->expectExceptionMessageMatches('/Report/');

        $this->generate('Duplicate');
    }

    /**
     * Under single table inheritance every subclass shares one table, so
     * without a discriminator scope CardPayment::all() would hand back
     * cash payments too.
     */
    #[Test]
    public function single_table_inheritance_scopes_subclasses_to_their_own_rows(): void
    {
        $projections = $this->generate('Inheritance');

        self::assertStringContainsString(
            "\$query->where('kind', 'card');",
            $projections['CardPayment']->code,
        );
        self::assertStringContainsString(
            "\$query->where('kind', 'cash');",
            $projections['CashPayment']->code,
        );
    }

    #[Test]
    public function the_inheritance_root_stays_unscoped(): void
    {
        // "every payment" is a meaningful query and the root is what
        // represents it
        $code = $this->generate('Inheritance')['Payment']->code;

        self::assertStringNotContainsString('addGlobalScope', $code);
        self::assertStringContainsString("protected \$table = 'payments';", $code);
    }

    #[Test]
    public function joined_inheritance_is_refused(): void
    {
        // spans several tables; an Eloquent model is bound to one
        $this->expectException(UnsupportedMapping::class);
        $this->expectExceptionMessageMatches('/JOINED/');

        $this->generate('Joined');
    }

    #[Test]
    public function only_narrows_generation_to_the_listed_entities(): void
    {
        $projections = $this->generate('Entities', new EntityFilter(
            only: ['*\\Entities\\Document'],
        ));

        self::assertSame(['Document'], array_keys($projections));
    }

    #[Test]
    public function except_removes_entities_after_only(): void
    {
        $projections = $this->generate('Entities', new EntityFilter(
            except: ['*\\Entities\\Enrollment', '*\\Entities\\Document'],
        ));

        $names = array_keys($projections);
        sort($names);

        self::assertSame(['Account', 'Profile'], $names);
    }

    /**
     * A relation pointing at an entity nobody projected would reference a
     * class that was never generated. Skipping it keeps the projection
     * loadable; the warning says why the relation is missing.
     */
    #[Test]
    public function relations_to_excluded_entities_are_skipped_with_a_warning(): void
    {
        $projections = $this->generate('Entities', new EntityFilter(
            except: ['*\\Entities\\Profile'],
        ));

        $account = $projections['Account'];

        self::assertArrayNotHasKey('Profile', $projections);
        self::assertStringNotContainsString('Profile::class', $account->code);
        self::assertStringNotContainsString('@property Profile', $account->code);

        self::assertCount(1, $account->warnings);
        self::assertStringContainsString('Skipped relation Account::$profile', $account->warnings[0]);
    }

    #[Test]
    public function excluding_everything_yields_nothing_rather_than_broken_output(): void
    {
        self::assertSame([], $this->generate('Entities', new EntityFilter(except: ['*'])));
    }

    #[Test]
    public function generated_code_is_syntactically_valid_php(): void
    {
        $all = [...$this->generate(), ...$this->generate('Inheritance'), ...$this->generate('Collide')];

        foreach ($all as $class => $projection) {
            $file = tempnam(sys_get_temp_dir(), 'projection').'.php';
            file_put_contents($file, $projection->code);

            exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $status);
            unlink($file);

            self::assertSame(0, $status, $class.' does not parse: '.implode("\n", $output));
        }
    }
}
