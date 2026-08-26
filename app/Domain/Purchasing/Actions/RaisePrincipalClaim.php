<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Masters\Models\BusinessPartner;
use App\Domain\Purchasing\Exceptions\InvalidPrincipalClaim;
use App\Domain\Purchasing\Models\PrincipalClaim;
use App\Domain\Purchasing\Models\PrincipalClaimLine;
use App\Support\Posting\DocumentNumberer;
use App\Support\Posting\PostingTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Claims expired, damaged or recalled goods back from the principal (F-23).
 *
 * Deliberately moves no stock. Writing the goods off is a stock adjustment and
 * a separate decision — goods can be claimed while still on the shelf awaiting
 * collection, and can be written off without anyone ever claiming for them.
 * Tying the two together would mean one could not happen without the other.
 */
final class RaisePrincipalClaim
{
    private const TYPES = ['expired', 'damaged', 'recall'];

    public function __construct(private readonly DocumentNumberer $numberer) {}

    /** @param  list<array{product_id: string, batch_id?: string, qty_base: string, unit_price: string}>  $lines */
    public function __invoke(
        BusinessPartner $principal,
        string $claimType,
        array $lines,
        ?DateTimeInterface $claimDate = null,
    ): PrincipalClaim {
        if (! $principal->is_principal) {
            throw new InvalidPrincipalClaim(sprintf('%s is not a principal.', $principal->name));
        }

        if (! in_array($claimType, self::TYPES, true)) {
            throw new InvalidPrincipalClaim(sprintf('"%s" is not one of %s.', $claimType, implode(', ', self::TYPES)));
        }

        if ($lines === []) {
            throw new InvalidPrincipalClaim('A claim needs at least one line.');
        }

        $claimDate = CarbonImmutable::instance($claimDate ?? CarbonImmutable::now())->startOfDay();

        return PostingTransaction::run(function () use ($principal, $claimType, $lines, $claimDate): PrincipalClaim {
            $claim = PrincipalClaim::create([
                'document_number' => $this->numberer->next('CLM', $claimDate),
                'principal_id' => $principal->getKey(),
                'claim_date' => $claimDate->format('Y-m-d'),
                'claim_type' => $claimType,
            ]);

            $total = BigDecimal::zero();

            foreach ($lines as $line) {
                $quantity = BigDecimal::of($line['qty_base']);
                $price = BigDecimal::of($line['unit_price']);

                if (! $quantity->isPositive()) {
                    throw new InvalidPrincipalClaim('Claimed quantities must be more than zero.');
                }

                PrincipalClaimLine::create([
                    'claim_id' => $claim->getKey(),
                    'product_id' => $line['product_id'],
                    'batch_id' => $line['batch_id'] ?? null,
                    'qty_base' => $quantity,
                    'unit_price' => $price,
                ]);

                $total = $total->plus($quantity->multipliedBy($price));
            }

            $claim->forceFill(['total_amount' => $total->toScale(2, RoundingMode::HALF_UP)])->save();

            return $claim->refresh();
        });
    }
}
