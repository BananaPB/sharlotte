<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Format;
use Illuminate\Database\Seeder;

/**
 * The closed, read-only list of formats (packaging/shape words) units attach a gram weight
 * to (docs/decisions.md entry 8). `code` is the stable English identity; both French labels
 * are stored because display picks singular/plural by quantity and plurals are never
 * computed in code.
 */
class FormatSeeder extends Seeder
{
    /**
     * @var list<array{code: string, label_fr: string, label_fr_plural: string}>
     */
    private const FORMATS = [
        ['code' => 'slice', 'label_fr' => 'tranche', 'label_fr_plural' => 'tranches'],
        ['code' => 'bottle', 'label_fr' => 'bouteille', 'label_fr_plural' => 'bouteilles'],
        // "boîte" keeps its circumflex on purpose (correct French spelling).
        ['code' => 'box', 'label_fr' => 'boîte', 'label_fr_plural' => 'boîtes'],
        ['code' => 'pack', 'label_fr' => 'paquet', 'label_fr_plural' => 'paquets'],
        ['code' => 'piece', 'label_fr' => 'pièce', 'label_fr_plural' => 'pièces'],
        ['code' => 'clove', 'label_fr' => 'gousse', 'label_fr_plural' => 'gousses'],
    ];

    /**
     * Run the database seeds.
     *
     * updateOrCreate on `code` so this is safe to re-run.
     */
    public function run(): void
    {
        foreach (self::FORMATS as $format) {
            Format::query()->updateOrCreate(['code' => $format['code']], $format);
        }
    }
}
