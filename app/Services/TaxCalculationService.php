<?php
namespace App\Services;
use App\Models\TaxRate;
use App\Models\TaxSnapshot;
use Illuminate\Database\Eloquent\Model;
class TaxCalculationService
{
    public function calculateExclusive(float|string $amount, float|string $rate): array { $taxable=$this->round($amount); $tax=$this->round($taxable*((float)$rate/100)); return ['taxable_amount'=>$taxable,'tax_amount'=>$tax,'total_with_tax'=>$this->round($taxable+$tax),'rate'=>(float)$rate,'price_mode'=>'exclusive']; }
    public function calculateInclusive(float|string $amountWithTax, float|string $rate): array { $total=$this->round($amountWithTax); $taxable=$this->round($total/(1+((float)$rate/100))); return ['taxable_amount'=>$taxable,'tax_amount'=>$this->round($total-$taxable),'total_with_tax'=>$total,'rate'=>(float)$rate,'price_mode'=>'inclusive']; }
    public function createSnapshot(Model $source, ?TaxRate $rate, float|string $amount, ?string $currency = null, ?float $legacyTaxAmount = null): TaxSnapshot
    {
        $result = $rate ? ($rate->price_mode === 'inclusive' ? $this->calculateInclusive($amount, $rate->rate) : $this->calculateExclusive($amount, $rate->rate)) : ['taxable_amount'=>$this->round($amount),'tax_amount'=>$this->round($legacyTaxAmount ?? 0),'total_with_tax'=>$this->round((float)$amount + ($legacyTaxAmount ?? 0)),'rate'=>null,'price_mode'=>config('coremarket.vat.default_price_mode', 'exclusive')];
        return TaxSnapshot::query()->create(array_merge($result, ['source_type'=>$source::class,'source_id'=>$source->getKey(),'tax_rate_id'=>$rate?->id,'tax_name'=>$rate?->name ?? ($legacyTaxAmount ? 'Legacy tax' : null),'tax_code'=>$rate?->code,'tax_type'=>$rate?->tax_type ?? ($legacyTaxAmount ? 'other' : null),'currency'=>$currency,'metadata'=>$rate ? null : ['warning'=>$legacyTaxAmount ? 'Legacy tax amount has no configured tax rate.' : 'No tax rate was configured.']]));
    }
    private function round(float|string $amount): float { return round((float)$amount, 2, PHP_ROUND_HALF_UP); }
}
