<?php
namespace Database\Seeders;
use App\Models\AccountingAccount;
use Illuminate\Database\Seeder;
class AccountingCoreSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['1000','Cash','asset','debit'], ['1100','Accounts Receivable','asset','debit'], ['1200','Inventory','inventory','debit'], ['1300','Tax Receivable / VAT Input','tax','debit'],
            ['2000','Accounts Payable','liability','credit'], ['2100','VAT Payable / Output VAT','tax','credit'], ['2200','Tax Clearing','clearing','credit'],
            ['4000','Sales Revenue','income','credit'], ['5000','Cost of Goods Sold','cost_of_goods_sold','debit'], ['6000','Operating Expenses','expense','debit'],
            ['9000','Inventory Adjustment Clearing','clearing','credit'], ['9100','Returns Clearing','clearing','credit'],
        ] as [$code, $name, $type, $balance]) AccountingAccount::query()->updateOrCreate(['code'=>$code], ['name'=>$name,'type'=>$type,'normal_balance'=>$balance,'is_system'=>true,'is_active'=>true]);
    }
}
