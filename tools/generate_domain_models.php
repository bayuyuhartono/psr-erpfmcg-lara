<?php

declare(strict_types=1);

use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$contexts = [
    '000100' => 'Masters', '000200' => 'Masters', '000300' => 'Masters', '000400' => 'Masters',
    '000500' => 'Inventory', '000600' => 'Purchasing', '000700' => 'Sales',
    '000800' => 'Consignment', '000900' => 'Finance', '001000' => 'Accounting',
];
$models = [];
$written = 0;
$skipped = 0;
$externalModels = ['users' => ['class' => 'User', 'fqcn' => 'App\\Models\\User']];

foreach (glob($root.'/database/migrations/2026_08_25_*.php') as $file) {
    if (! preg_match('/_(00(?:0[1-9]|10)00)_/', basename($file), $match)) {
        continue;
    }
    $context = $contexts[$match[1]] ?? null;
    if (! $context) {
        continue;
    }
    $source = file_get_contents($file);
    preg_match_all("/Schema::create\('([^']+)'.*?\n        \}\);/s", $source, $tables, PREG_SET_ORDER);
    foreach ($tables as $tableMatch) {
        $table = $tableMatch[1];
        $block = $tableMatch[0];
        $models[$table] = [
            'class' => Str::studly(Str::singular($table)),
            'context' => $context,
            'auditable' => str_contains($block, '->auditable()'),
            'incrementing' => str_contains($block, 'bigIncrements'),
            'uuid' => str_contains($block, "uuid('id')->primary()"),
            'created_at' => str_contains($block, "timestamp('created_at')"),
            'updated_at' => str_contains($block, "timestamp('updated_at')"),
            'block' => $block,
            'relations' => [],
            'casts' => [],
        ];
    }
}

foreach ($models as $table => &$meta) {
    foreach ([2 => 'money', 4 => 'quantity'] as $scale => $kind) {
        $patterns = $scale === 2 ? ['money', 'percent'] : ['qty', 'cost'];
        foreach ($patterns as $macro) {
            preg_match_all("/->{$macro}\('([^']+)'\)/", $meta['block'], $matches);
            foreach ($matches[1] as $column) {
                $meta['casts'][$column] = $scale;
            }
        }
    }
    preg_match_all("/->decimal\('([^']+)',\s*\d+,\s*(\d+)\)/", $meta['block'], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $meta['casts'][$match[1]] = (int) $match[2];
    }
    preg_match_all("/->foreignUuid\('([^']+)'\)([^;]+);/", $meta['block'], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $column = $match[1];
        if (preg_match("/constrained\('([^']+)'\)/", $match[2], $target)) {
            $targetTable = $target[1];
        } else {
            $targetTable = Str::plural(Str::beforeLast($column, '_id'));
        }
        if (isset($models[$targetTable]) || isset($externalModels[$targetTable])) {
            $meta['relations'][$column] = $targetTable;
        }
    }
}
unset($meta);

foreach ([
    'territories' => ['parent_id' => 'territories'],
    'categories' => ['parent_id' => 'categories'],
    'chart_of_accounts' => ['parent_id' => 'chart_of_accounts'],
    'payments' => ['cash_account_id' => 'chart_of_accounts'],
    'sales_invoices' => ['settlement_id' => 'consignment_settlements'],
] as $table => $relations) {
    $models[$table]['relations'] = array_merge($models[$table]['relations'], $relations);
}

foreach ($models as $childTable => $child) {
    foreach ($child['relations'] as $column => $parentTable) {
        if (isset($models[$parentTable])) {
            $models[$parentTable]['children'][] = [$childTable, $column];
        }
    }
}

foreach ($models as $table => $meta) {
    $namespace = "App\\Domain\\{$meta['context']}\\Models";
    $uses = [];
    $base = $meta['auditable'] ? 'DomainModel' : 'PlainModel';
    if ($meta['incrementing']) {
        $base = 'IncrementingModel';
    }
    if (! $meta['uuid'] && ! $meta['incrementing']) {
        $base = 'PivotModel';
    }
    $uses[] = "App\\Domain\\Shared\\Models\\{$base}";
    if ($meta['casts']) {
        $uses[] = 'App\\Support\\Money\\DecimalCast';
    }
    if ($meta['relations']) {
        $uses[] = 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo';
    }
    if (! empty($meta['children'])) {
        $uses[] = 'Illuminate\\Database\\Eloquent\\Relations\\HasMany';
    }
    if (in_array($table, ['stock_ledger', 'stock_balances'], true)) {
        $uses[] = 'App\\Domain\\Inventory\\Scopes\\OwnedStock';
    }
    foreach ($meta['relations'] as $target) {
        if (isset($externalModels[$target])) {
            $uses[] = $externalModels[$target]['fqcn'];
        } elseif ($models[$target]['context'] !== $meta['context']) {
            $uses[] = "App\\Domain\\{$models[$target]['context']}\\Models\\{$models[$target]['class']}";
        }
    }
    foreach ($meta['children'] ?? [] as [$child]) {
        if ($models[$child]['context'] !== $meta['context']) {
            $uses[] = "App\\Domain\\{$models[$child]['context']}\\Models\\{$models[$child]['class']}";
        }
    }
    $uses = array_unique($uses);
    sort($uses);
    $code = "<?php\n\nnamespace {$namespace};\n\n";
    foreach ($uses as $use) {
        $code .= "use {$use};\n";
    }
    $code .= "\nfinal class {$meta['class']} extends {$base}\n{\n";
    $code .= "    protected \$table = '{$table}';\n\n";
    if ($meta['incrementing']) {
        $code .= "    public \$incrementing = true;\n\n    protected \$keyType = 'int';\n\n";
    }
    if (! $meta['auditable'] && $base !== 'PivotModel') {
        if ($meta['created_at'] && ! $meta['updated_at']) {
            $code .= "    public const UPDATED_AT = null;\n\n";
        }
        if (! $meta['created_at'] && $meta['updated_at']) {
            $code .= "    public const CREATED_AT = null;\n\n";
        }
        if (! $meta['created_at'] && ! $meta['updated_at']) {
            $code .= "    public \$timestamps = false;\n\n";
        }
    }
    if ($meta['casts']) {
        $code .= "    protected function casts(): array\n    {\n        return [\n";
        foreach ($meta['casts'] as $column => $scale) {
            $code .= "            '{$column}' => DecimalCast::class.':{$scale}',\n";
        }
        $code .= "        ];\n    }\n\n";
    }
    if (in_array($table, ['stock_ledger', 'stock_balances'], true)) {
        $parentBoot = $meta['auditable'] ? "        parent::booted();\n" : '';
        $code .= "    protected static function booted(): void\n    {\n{$parentBoot}        static::addGlobalScope(new OwnedStock);\n    }\n\n";
        $code .= "    public function scopeIncludingConsigned(\$query)\n    {\n        return \$query->withoutGlobalScope(OwnedStock::class);\n    }\n\n";
    }
    foreach ($meta['relations'] as $column => $target) {
        $method = Str::camel(Str::beforeLast($column, '_id'));
        $class = $models[$target]['class'] ?? $externalModels[$target]['class'];
        $code .= "    public function {$method}(): BelongsTo\n    {\n        return \$this->belongsTo({$class}::class, '{$column}');\n    }\n\n";
    }
    $childMethodCounts = array_count_values(array_map(fn ($relation) => $relation[0], $meta['children'] ?? []));
    foreach ($meta['children'] ?? [] as [$child, $column]) {
        $method = Str::camel($child);
        if (($childMethodCounts[$child] ?? 0) > 1) {
            $method .= Str::studly(Str::beforeLast($column, '_id'));
        }
        $class = $models[$child]['class'];
        $code .= "    public function {$method}(): HasMany\n    {\n        return \$this->hasMany({$class}::class, '{$column}');\n    }\n\n";
    }
    $code = rtrim($code)."\n}\n";
    $directory = $root."/app/Domain/{$meta['context']}/Models";
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    // Scaffolds new tables only. The models are hand-maintained now — they
    // carry state machines, guards, and casts this tool knows nothing about,
    // and overwriting them would delete that silently.
    $path = "{$directory}/{$meta['class']}.php";
    if (file_exists($path)) {
        $skipped++;

        continue;
    }

    file_put_contents($path, $code);
    $written++;
}

fwrite(STDOUT, "Scaffolded {$written} new model(s); left {$skipped} existing model(s) alone.\n");
