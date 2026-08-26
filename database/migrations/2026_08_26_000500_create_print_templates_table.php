<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not in the schema doc, which does not model printing at all — but the PRD
 * calls print layout the most underestimated requirement and a week-one
 * rejection risk, and requires layouts "customisable per client without code
 * changes".
 *
 * So the layout is a row, not a file. A client whose invoice needs the tax
 * number three lines higher edits this; nobody forks a template directory per
 * installation, which is how a product becomes fifteen products.
 *
 * `body` holds placeholder text, deliberately not Blade: a template is edited
 * by an administrator, and Blade would let them run PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // 'sales_invoice' | 'delivery' | 'invoice_exchange'
            $table->string('document_type');
            // 'dotmatrix' | 'a4'
            $table->string('format')->default('dotmatrix');
            $table->string('name');
            // Continuous form is measured in characters, not millimetres.
            $table->unsignedSmallInteger('width_chars')->default(80);
            $table->unsignedSmallInteger('lines_per_page')->default(66);
            // How many parts the paper has: file copy, customer, driver.
            $table->unsignedTinyInteger('copies')->default(2);
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->auditable();

            $table->unique(['document_type', 'format', 'name']);
            $table->index(['document_type', 'format', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
    }
};
