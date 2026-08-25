<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;

/**
 * Schema conventions for the ERP, expressed as Blueprint macros.
 *
 * The schema doc states two rules that are easy to break by accident and
 * expensive to discover later: every table carries audit columns, and no
 * quantity or monetary value is ever stored as a float. Both are enforced
 * here so a migration cannot get them wrong without going out of its way.
 */
class SchemaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // created_at / created_by / updated_at / updated_by / deleted_at.
        // created_by and updated_by carry no foreign key to users: the users
        // table itself needs these columns, and a self-referencing constraint
        // on every table buys nothing at this volume.
        Blueprint::macro('auditable', function (): void {
            /** @var Blueprint $this */
            $this->timestamps();
            $this->uuid('created_by')->nullable();
            $this->uuid('updated_by')->nullable();
            $this->softDeletes();
        });

        // Money. Rupiah amounts, two decimals to survive VAT and discount
        // arithmetic before rounding at presentation.
        Blueprint::macro('money', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 18, 2);
        });

        // Quantities, always in the product's base unit.
        Blueprint::macro('qty', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 18, 4);
        });

        // Unit cost. Four decimals: average cost divides by quantity and the
        // rounding error compounds across every movement if held at two.
        Blueprint::macro('cost', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 18, 4);
        });

        // Percentages: discount rates, VAT, shelf-life thresholds.
        Blueprint::macro('percent', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->decimal($column, 5, 2);
        });
    }
}
