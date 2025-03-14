<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\Diamond;
use Exception;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

class ImportDiamondsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $filePath;
    public $timeout = 3600; // Job timeout set to 1 hour
    public $tries = 3; // Number of retry attempts

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \Log::info("Packet processed successfully: " . $this->filePath);

        $filePath = $this->filePath; // Pass file path when dispatching the job
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        // Use the first row as keys
        $header = array_shift($data);
        $newHeader = [];
        foreach ($header as $key => $value) {
            if($key == 0) {
                $newHeader[] = 'id';
            } else {
                $newHeader[] = $this->format_column($value);
            }
        }
        $formattedData = array_map(function ($row) use ($newHeader) {
            return array_combine($newHeader, $row);
        }, $data);

        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        $file = public_path('excel/data.json');
        file_put_contents($file, $jsonData);

        // echo "<pre>";
        // print_r($formattedData);
        // die;

        if (count($formattedData) > 0) {
            Diamond::truncate();
        }

        foreach ($formattedData as $key => $value) {

            array_shift($value); // Remove first element
            array_pop($value); // Remove last element

            $value['report_date'] = !empty($value['report_date']) ? date("Y-m-d", strtotime($value['report_date'])) : date("Y-m-d");
            $value['ratio'] = !empty($value['ratio']) ? sprintf("%.2f", $value['ratio']) : sprintf("%.2f", ((float)($value['length'] ?? 0) / (((float)$value['width'] > 0) ? (float)$value['width'] : 1) ?? 0));
            $value['rap_amount'] = !empty($value['rap_amount']) ? sprintf("%.2f", $value['rap_amount']) : sprintf("%.2f", (((float)($value['weight'] ?? 0)) * (float)($value['live_rap'] ?? 0)));
            $value['price_per_carat'] = !empty($value['price_per_carat']) ? sprintf("%.2f", $value['price_per_carat']) : sprintf("%.2f", (((float)($value['live_rap'] ?? 0) * (((float)($value['discounts'] ?? 0)) / 100)) + (float)($value['live_rap'] ?? 0)));
            $value['total_price'] = !empty($value['total_price']) ? sprintf("%.2f", $value['total_price']) : sprintf("%.2f", (((float)($value['weight'] ?? 0)) * ($value['price_per_carat'] ?? 0)));
            $value['bargaining_price_per_carat'] = !empty($value['bargaining_price_per_carat']) ? sprintf("%.2f", $value['bargaining_price_per_carat']) : sprintf("%.2f", ((float)($value['bargaining_price_per_carat'] ?? 0)));
            $value['bargaining_total_price'] = !empty($value['bargaining_total_price']) ? sprintf("%.2f", $value['bargaining_total_price']) : sprintf("%.2f", ((float)($value['weight'] ?? 0) * ((float)($value['bargaining_price_per_carat'] ?? 0))));

            // echo "<pre>";
            // print_r($value);
            // die;

            if(!empty($value['stock_id'])) {
                $getRecord = Diamond::where("stock_id", $value['stock_id'])->first();
                if(!empty($getRecord)) {
                    continue;
                    // Diamond::where("stock_id", $value['stock_id'])->update($value);
                } else {
                    Diamond::create($value);
                }
            }
        }

        // $file->move(public_path('excel'), $newFileName);
        // $filePath = public_path('excel/'.$newFileName);

        return redirect()->back()->with('success', 'Excel file imported successfully.');
    }
}
