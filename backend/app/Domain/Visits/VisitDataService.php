<?php

namespace App\Domain\Visits;

use App\Models\MerchandisingRecord;
use App\Models\OsaRecord;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Visit;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;

/** Sales, OSA and merchandising capture. Totals are always computed by the server. */
class VisitDataService
{
    public function __construct(private VisitService $visits) {}

    public function addSale(Visit $visit, array $data): Sale
    {
        $this->visits->assertEditable($visit);

        return DB::transaction(function () use ($visit, $data) {
            if (!empty($data['client_uuid'])) {
                $dup = Sale::query()->where('visit_id', $visit->id)->where('client_uuid', $data['client_uuid'])->first();
                if ($dup) {
                    return $dup->load('items');
                }
            }

            $sale = Sale::query()->create([
                'client_uuid' => $data['client_uuid'] ?? null, 'visit_id' => $visit->id, 'payment_method' => $data['payment_method'] ?? null,
                'captured_at' => $data['captured_at'] ?? now(), 'notes' => $data['notes'] ?? null, 'total_value' => 0,
            ]);

            $total = 0;
            foreach ($data['items'] as $item) {
                $product = $this->campaignProduct($visit, $item['product_id']);
                $price = $item['unit_price'] ?? $product->unit_price;
                if ($price === null) {
                    throw new DomainRuleException("Product \"{$product->name}\" has no price; provide unit_price.", 'price_required');
                }
                $line = round($price * $item['quantity'], 2);
                $total += $line;
                SaleItem::query()->create([
                    'sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => $item['quantity'],
                    'unit_price' => $price, 'line_total' => $line, 'is_promo' => $item['is_promo'] ?? false,
                ]);
            }
            $sale->update(['total_value' => round($total, 2)]);

            return $sale->load('items.product:id,name,sku');
        });
    }

    public function saveOsa(Visit $visit, array $data): OsaRecord
    {
        $this->visits->assertEditable($visit);
        $product = $this->campaignProduct($visit, $data['product_id']);

        $outOfStock = (bool) ($data['out_of_stock'] ?? false);
        $available = $outOfStock ? false : (bool) ($data['available'] ?? true);

        return OsaRecord::query()->updateOrCreate(
            ['visit_id' => $visit->id, 'product_id' => $product->id],
            [
                'client_uuid' => $data['client_uuid'] ?? null, 'available' => $available, 'out_of_stock' => $outOfStock || !$available,
                'shelf_quantity' => $data['shelf_quantity'] ?? null, 'backroom_quantity' => $data['backroom_quantity'] ?? null,
                'facings' => $data['facings'] ?? null, 'share_of_shelf' => $data['share_of_shelf'] ?? null, 'price_on_shelf' => $data['price_on_shelf'] ?? null,
                'display_compliant' => $data['display_compliant'] ?? null, 'visibility_ok' => $data['visibility_ok'] ?? null,
                'captured_at' => $data['captured_at'] ?? now(), 'notes' => $data['notes'] ?? null,
            ],
        );
    }

    public function addMerchandising(Visit $visit, array $data): MerchandisingRecord
    {
        $this->visits->assertEditable($visit);
        if (!empty($data['product_id'])) {
            $this->campaignProduct($visit, $data['product_id']);
        }
        if (!empty($data['client_uuid'])) {
            $dup = MerchandisingRecord::query()->where('visit_id', $visit->id)->where('client_uuid', $data['client_uuid'])->first();
            if ($dup) {
                return $dup;
            }
        }

        return MerchandisingRecord::query()->create([
            'visit_id' => $visit->id, 'client_uuid' => $data['client_uuid'] ?? null, 'product_id' => $data['product_id'] ?? null,
            'posm_present' => $data['posm_present'] ?? null, 'posm_type' => $data['posm_type'] ?? null, 'shelf_position' => $data['shelf_position'] ?? null,
            'planogram_compliant' => $data['planogram_compliant'] ?? null, 'price_compliant' => $data['price_compliant'] ?? null,
            'competitor_present' => $data['competitor_present'] ?? null, 'competitor_notes' => $data['competitor_notes'] ?? null,
            'captured_at' => $data['captured_at'] ?? now(), 'notes' => $data['notes'] ?? null,
        ]);
    }

    /** A product may only be captured if it belongs to the visit's campaign. */
    private function campaignProduct(Visit $visit, string $productId): Product
    {
        $product = Product::query()->whereKey($productId)
            ->whereIn('id', DB::table('campaign_products')->where('campaign_id', $visit->campaign_id)->select('product_id'))->first();
        if (!$product) {
            throw new DomainRuleException('The product is not part of this campaign.', 'product_not_in_campaign');
        }
        return $product;
    }
}
