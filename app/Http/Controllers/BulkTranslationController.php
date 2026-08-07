<?php

namespace App\Http\Controllers;

use App\Models\{Brand, BrandTranslation, Category, CategoryTranslation, Language, Product, ProductTranslation, Translation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BulkTranslationController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:header_setup');
    }
    public function index() { return view('backend.setup_configurations.languages.bulk_excel'); }
    public function export()
    {
        $languages = Language::query()->where('status', 1)->pluck('code')->prepend('en')->unique()->values();
        $book = new Spreadsheet(); $ui = $book->getActiveSheet(); $ui->setTitle('ui_translations');
        $headers = array_merge(['lang_key'], $languages->all()); $ui->fromArray($headers, null, 'A1');
        foreach (Translation::query()->where('lang','en')->orderBy('lang_key')->cursor() as $row) { $line=[$row->lang_key]; foreach($languages as $lang)$line[] = Translation::query()->where(['lang'=>$lang,'lang_key'=>$row->lang_key])->value('lang_value') ?? ''; $ui->fromArray($line,null,'A'.($ui->getHighestRow()+1)); }
        $catalog = $book->createSheet(); $catalog->setTitle('catalog_translations'); $catalog->fromArray(array_merge(['entity_type','entity_id','identity','field'], $languages->all()), null, 'A1');
        foreach ([['category',Category::class,CategoryTranslation::class,['name']],['brand',Brand::class,BrandTranslation::class,['name']],['product',Product::class,ProductTranslation::class,['name','description','unit']]] as [$type,$model,$translation,$fields]) foreach ($model::query()->cursor() as $entity) foreach ($fields as $field) { $line=[$type,$entity->id,$entity->slug ?? $entity->barcode ?? $entity->id,$field]; foreach($languages as $lang)$line[]=$translation::query()->where($type.'_id',$entity->id)->where('lang',$lang)->value($field)??''; $catalog->fromArray($line,null,'A'.($catalog->getHighestRow()+1)); }
        return response()->streamDownload(function() use($book){(new Xlsx($book))->save('php://output');}, 'coremarket-translations.xlsx', ['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
    public function import(Request $request)
    {
        $request->validate(['translation_file'=>['required','file','mimes:xlsx,xls','max:20480']]);
        try { $sheets=Excel::toArray([], $request->file('translation_file')); $languages=Language::query()->where('status',1)->pluck('code')->prepend('en')->unique()->all(); $updated=0; \DB::transaction(function()use($sheets,$languages,&$updated){ $updated += $this->importUi($sheets[0]??[],$languages); $updated += $this->importCatalog($sheets[1]??[],$languages); }); foreach($languages as $language)Cache::forget("translations-{$language}"); }
        catch(\Throwable $exception){ report($exception); return back()->withErrors(['translation_file'=>translate('Translation workbook could not be imported. No changes were saved.')]); }
        return back()->with('success', "{$updated} translations updated.");
    }
    private function rows(array $sheet): array { if(!$sheet)return []; $headers=array_map(fn($v)=>trim((string)$v),array_shift($sheet)); return collect($sheet)->map(fn($row)=>array_combine($headers,array_pad($row,count($headers),null)))->filter(fn($r)=>collect($r)->filter(fn($v)=>filled($v))->isNotEmpty())->all(); }
    private function importUi(array $sheet,array $languages): int { $updated=0; foreach($this->rows($sheet) as $row){$key=trim((string)($row['lang_key']??''));if(!$key)continue;foreach($languages as $lang)if(filled($row[$lang]??null)){Translation::updateOrCreate(['lang'=>$lang,'lang_key'=>$key],['lang_value'=>$row[$lang]]);$updated++;}}return $updated; }
    private function importCatalog(array $sheet,array $languages): int { $models=['category'=>[Category::class,CategoryTranslation::class,'category_id',['name']],'brand'=>[Brand::class,BrandTranslation::class,'brand_id',['name']],'product'=>[Product::class,ProductTranslation::class,'product_id',['name','description','unit']]];$updated=0;foreach($this->rows($sheet) as $row){[$model,$translation,$foreign,$fields]=$models[$row['entity_type']??'']??[null,null,null,[]];if(!$model||!in_array($row['field']??'',$fields,true))continue;$entity=$model::find($row['entity_id']??0);if(!$entity)throw new \RuntimeException('Catalog translation references a missing record.');foreach($languages as $lang)if(filled($row[$lang]??null)){$record=$translation::firstOrNew([$foreign=>$entity->id,'lang'=>$lang]);$record->{$row['field']}=$row[$lang];$record->save();$updated++;}}return $updated; }
}
