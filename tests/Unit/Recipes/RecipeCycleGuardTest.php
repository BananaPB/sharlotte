<?php

declare(strict_types=1);

use App\Models\Preparation;
use App\Models\RecipeLine;
use App\Models\User;
use App\Recipes\RecipeCycleGuard;
use Database\Factories\PreparationFactory;
use Database\Factories\RecipeLineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function cycleGuardPreparation(User $owner): Preparation
{
    /** @var Preparation $preparation */
    $preparation = PreparationFactory::new()->ownedBy($owner)->create();

    return $preparation;
}

/**
 * "$parent uses $component", saved through the model (so the guard itself must allow it).
 */
function cycleGuardUses(Preparation $parent, Preparation $component): void
{
    RecipeLineFactory::new()->forPreparation($parent)->withComponentPreparation($component)->create();
}

/**
 * Same link written with a bulk insert, which skips model events: the only way to put a
 * cycle in the data, as legacy or concurrent writes could.
 */
function cycleGuardForceUses(Preparation $parent, Preparation $component): void
{
    RecipeLine::query()->insert([
        'parent_preparation_id' => $parent->id,
        'component_preparation_id' => $component->id,
        'position' => 0,
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('sees a preparation used inside itself as a cycle', function () {
    $a = cycleGuardPreparation(User::factory()->create());

    expect((new RecipeCycleGuard)->wouldCreateCycle($a->id, $a->id))->toBeTrue();
});

test('sees a cycle when the component already contains the parent directly', function () {
    $owner = User::factory()->create();
    $a = cycleGuardPreparation($owner);
    $b = cycleGuardPreparation($owner);
    cycleGuardUses($b, $a); // B contains A

    // Putting B inside A would make A contain B contain A.
    expect((new RecipeCycleGuard)->wouldCreateCycle($a->id, $b->id))->toBeTrue();
});

test('sees a cycle through three levels of preparations', function () {
    $owner = User::factory()->create();
    [$a, $b, $c, $d] = [
        cycleGuardPreparation($owner), cycleGuardPreparation($owner),
        cycleGuardPreparation($owner), cycleGuardPreparation($owner),
    ];
    cycleGuardUses($d, $c);
    cycleGuardUses($c, $b);
    cycleGuardUses($b, $a); // D > C > B > A

    expect((new RecipeCycleGuard)->wouldCreateCycle($a->id, $d->id))->toBeTrue();
});

test('allows a shared component used by two preparations of the same parent', function () {
    $owner = User::factory()->create();
    [$a, $b, $c, $d] = [
        cycleGuardPreparation($owner), cycleGuardPreparation($owner),
        cycleGuardPreparation($owner), cycleGuardPreparation($owner),
    ];
    cycleGuardUses($a, $b);
    cycleGuardUses($a, $c);
    cycleGuardUses($b, $d);

    // C may use D too: A reaches D twice, which is a diamond, not a cycle.
    expect((new RecipeCycleGuard)->wouldCreateCycle($c->id, $d->id))->toBeFalse();
});

test('allows a component whose tree never reaches the parent', function () {
    $owner = User::factory()->create();
    [$a, $b, $c] = [cycleGuardPreparation($owner), cycleGuardPreparation($owner), cycleGuardPreparation($owner)];
    cycleGuardUses($b, $c);

    expect((new RecipeCycleGuard)->wouldCreateCycle($a->id, $b->id))->toBeFalse();
});

test('terminates when the data already contains a cycle elsewhere', function () {
    $owner = User::factory()->create();
    [$a, $b, $x, $y] = [
        cycleGuardPreparation($owner), cycleGuardPreparation($owner),
        cycleGuardPreparation($owner), cycleGuardPreparation($owner),
    ];
    cycleGuardForceUses($x, $y);
    cycleGuardForceUses($y, $x);
    cycleGuardForceUses($b, $x); // B leads into the X <-> Y loop, never to A

    expect((new RecipeCycleGuard)->wouldCreateCycle($a->id, $b->id))->toBeFalse();
});

test('ignores another user\'s preparations', function () {
    $owner = User::factory()->create();
    $a = cycleGuardPreparation($owner);
    $b = cycleGuardPreparation($owner);
    $stranger = cycleGuardPreparation(User::factory()->create());
    // Forced, as the model would refuse a cross-owner link: a stranger's line that
    // would close a loop if it were counted.
    cycleGuardForceUses($b, $stranger);
    cycleGuardForceUses($stranger, $a);

    expect((new RecipeCycleGuard)->wouldCreateCycle($a->id, $b->id))->toBeFalse();
});

test('reads the structure in a single query', function () {
    $owner = User::factory()->create();
    [$a, $b, $c] = [cycleGuardPreparation($owner), cycleGuardPreparation($owner), cycleGuardPreparation($owner)];
    cycleGuardUses($c, $b);
    cycleGuardUses($b, $a);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    (new RecipeCycleGuard)->wouldCreateCycle($a->id, $c->id);

    expect($queries)->toBe(1);
});
