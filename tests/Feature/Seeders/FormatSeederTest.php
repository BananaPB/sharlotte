<?php

declare(strict_types=1);

use App\Models\Format;
use Database\Seeders\FormatSeeder;

// RefreshDatabase + TestCase are wired automatically for the Feature directory, see
// tests/Pest.php.

test('seeds exactly the 6 formats with their French singular and plural labels', function () {
    $this->seed(FormatSeeder::class);

    $formats = Format::query()->orderBy('code')->get()
        ->map(fn (Format $format): array => $format->only(['code', 'label_fr', 'label_fr_plural']))
        ->all();

    expect($formats)->toBe([
        ['code' => 'bottle', 'label_fr' => 'bouteille', 'label_fr_plural' => 'bouteilles'],
        ['code' => 'box', 'label_fr' => 'boîte', 'label_fr_plural' => 'boîtes'],
        ['code' => 'clove', 'label_fr' => 'gousse', 'label_fr_plural' => 'gousses'],
        ['code' => 'pack', 'label_fr' => 'paquet', 'label_fr_plural' => 'paquets'],
        ['code' => 'piece', 'label_fr' => 'pièce', 'label_fr_plural' => 'pièces'],
        ['code' => 'slice', 'label_fr' => 'tranche', 'label_fr_plural' => 'tranches'],
    ]);
});

test('is idempotent: re-running the seeder does not duplicate or fail', function () {
    $this->seed(FormatSeeder::class);
    $this->seed(FormatSeeder::class);

    expect(Format::query()->count())->toBe(6);
});

test('restores a format label that was changed, without creating a new row', function () {
    $this->seed(FormatSeeder::class);
    Format::query()->where('code', 'box')->update(['label_fr_plural' => 'boites']);

    $this->seed(FormatSeeder::class);

    expect(Format::query()->count())->toBe(6)
        ->and(Format::query()->where('code', 'box')->value('label_fr_plural'))->toBe('boîtes');
});
