<?php

namespace App\Http\Controllers;

use App\Domain\Products\Product;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductsController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $products = Product::query()
            ->when($request->filled('sku'), fn ($query) => $query->where('sku', 'like', '%'.$request->string('sku')->trim().'%'))
            ->when($request->filled('cost_min') && is_numeric($request->input('cost_min')), fn ($query) => $query->where('cost_price', '>=', (float) $request->input('cost_min')))
            ->when($request->filled('cost_max') && is_numeric($request->input('cost_max')), fn ($query) => $query->where('cost_price', '<=', (float) $request->input('cost_max')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('updated_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('updated_at', '<=', $request->date('to')))
            ->orderBy('sku')
            ->get();

        return response()->streamDownload(function () use ($products): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['sku', 'name', 'cost_price', 'markup_percent', 'currency', 'sale_price', 'updated_at']);

            $products->each(function (Product $product) use ($out): void {
                fputcsv($out, [
                    $product->sku,
                    $product->name,
                    number_format($product->cost_price, 2, '.', ''),
                    number_format($product->markup_percent, 2, '.', ''),
                    $product->currency,
                    number_format($product->sale_price, 2, '.', ''),
                    $product->updated_at?->toDateTimeString(),
                ]);
            });

            fclose($out);
        }, 'products.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
