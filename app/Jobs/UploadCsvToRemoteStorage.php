<?php

namespace App\Jobs;

use App\Mail\FileUploaded;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Arrear;
use App\Models\Branch;
use App\Models\District;
use App\Models\Officer;
use App\Models\PreviousEndMonth;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Sub_County;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;


class UploadCsvToRemoteStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;


    /**
     * Create a new job instance.
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }


    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
                $file = $this->filePath;
                $csv = array_map('str_getcsv', file($file));

                //truncate the sales and arrears table
                Sale::truncate();
                Arrear::truncate();
                if ($this->isLastDayOfMonth()) {
                    PreviousEndMonth::truncate();
                }

                //get the first row of the csv file and the first column and get the string after at
                $first_row = $csv[0];
                $first_column = $first_row[1];
                $first_column = Str::after($first_column, 'at');
                //remove spaces at the beginning and at the end of the string
                $first_column = trim($first_column);

                //add - where there is a space
                $first_column = str_replace(' ', '-', $first_column);

                //convert to date
                $current_date = Carbon::parse($first_column)->format('M-y');

                //create or update the where id = 1
                DB::table('upload_date')->updateOrInsert(
                    ['id' => 1],
                    ['upload_date' => $current_date]
                );

                for ($i = 5; $i < count($csv); $i++) {

                        // Extracting region_id from $csv[$i][0]
                        $regionData = explode('-', $csv[$i][0]);
                        $region_id = blank($regionData[0]) ? 3 : $regionData[0];

                        // Extracting product_id from $csv[$i][1]
                        $branchData = explode('-', $csv[$i][1]);
                        $branch_id = $branchData[0];
                        $found = Branch::where('branch_id', $branch_id)->first();
                        if (!$found) {
                            $branch = new Branch();
                            $branch->branch_id = $branch_id;
                            $branch->branch_name = $branchData[1];
                            $branch->region_id = $region_id;
                            $branch->save();

                            $branch_id = $branch->branch_id;
                        }

                        //extracting staff_id from $csv[$i][2]
                        $staffData = explode('-', $csv[$i][2]);
                        $staff_id = $staffData[0];
                        $full_name = count($staffData) > 2 ? $staffData[2] : $staffData[1];

                        $found = Officer::where('staff_id', $staff_id)->first();
                        if ($found) {
                            // Staff found, check if names match
                            if ($found->names !== $full_name) {
                                $password = bcrypt($staff_id);
                                // Names don't match, update name
                                $found->names = $full_name;
                                $found->user_type = 1;
                                $found->username = $staff_id;
                                $found->region_id = $region_id;
                                $found->branch_id = $branch_id;
                                $found->password = $password;
                                $found->un_hashed_password = $staff_id;
                                $found->save();
                            }
                        } else {
                            $password = bcrypt($staff_id);

                            // Staff not found, create new
                            $staff = new Officer();

                            $staff->staff_id = $staff_id;
                            $staff->names = $full_name;
                            $staff->user_type = 1;
                            $staff->username = $staff_id;
                            $staff->region_id = $region_id;
                            $staff->branch_id = $branch_id;
                            $staff->password = $password;
                            $staff->un_hashed_password = $staff_id;
                            $staff->save();

                        }

                        $product_id = $csv[$i][17];
                        $found = Product::where('product_id', $product_id)->first();
                        if (!$found) {
                            $product = new Product();
                            $product->product_id = $product_id;
                            $product->product_name = $csv[$i][18];
                            $product->save();

                            $product_id = $product->product_id;
                        }

                        $district_id = explode('-', $csv[$i][62])[0];
                        $district = District::firstOrCreate(
                            ['district_id' => $district_id],
                            [
                                'district_name' => "Unknown",
                                'region_id' => $region_id,
                            ]
                        );

                        $subcounty_id = explode('-', $csv[$i][63])[0];
                        $subcounty = Sub_County::firstOrCreate(
                            ['subcounty_id' => $subcounty_id],
                            [
                                'subcounty_name' => "Unknown",
                                'district_id' => $district_id,
                            ]
                        );

                        $village_id = null;
                        if (!empty($csv[$i][61])) {
                            $village = \App\Models\Village::firstOrCreate(
                                ['village_name' => $csv[$i][61]],
                                ['subcounty_id' => $subcounty_id]
                            );
                            $village_id = $village->village_id;
                        } else {
                            $village = \App\Models\Village::create([
                                'village_name' => 'Unknown',
                                'subcounty_id' => $subcounty_id,
                            ]);
                            $village_id = $village->village_id;
                        }

                        $csv[$i][47] = $csv[$i][47] == "" ? 1 : $csv[$i][47];

                        [$csv[$i][16], $csv[$i][27], $csv[$i][35], $csv[$i][40], $csv[$i][39], $csv[$i][36], $csv[$i][42], $csv[$i][43], $csv[$i][44], $csv[$i][33], $csv[$i][34]] = array_map(function ($value) {
                            return str_replace(',', '', $value);
                        }, [$csv[$i][16], $csv[$i][27], $csv[$i][35], $csv[$i][40], $csv[$i][39], $csv[$i][36], $csv[$i][42], $csv[$i][43], $csv[$i][44], $csv[$i][33], $csv[$i][34]]);

                        $customer_id_column = $csv[$i][7] == '' ? $csv[$i][12] : $csv[$i][7];
                        //get the customer data and save it to get the customer_id
                        //check if the customer exists by checking $csv[$i][7] or $csv[$i][12]
                        $customer = \App\Models\Customer::where('customer_id', $customer_id_column)->first();
                        //if the customer does not exist, create a new customer
                        if (!$customer) {
                            $customer = new \App\Models\Customer();
                            $customer->customer_id = $customer_id_column;
                            $customer->names = $csv[$i][8] ?? "Unknown";
                            $customer->phone = $csv[$i][9] ?? 'Unknown';

                            //save the customer
                            $customer->save();
                        }

                        $sale = new Sale();
                        $sale->staff_id = $staff_id;
                        $sale->product_id = $product_id;
                        $sale->disbursement_date = $csv[$i][30];
                        $sale->disbursement_amount = $csv[$i][27];
                        $sale->region_id = $region_id;
                        $sale->branch_id = $branch_id;
                        $sale->gender = $csv[$i][19];
                        $sale->number_of_children = $csv[$i][45];
                        $sale->number_of_group_members = $csv[$i][47];
                        $sale->number_of_women = $csv[$i][48];
                        $sale->group_id = blank($csv[$i][4]) ? $csv[$i][12] : $csv[$i][4];
                        $sale->save();

                        $arrear = new Arrear();
                        $arrear->staff_id = $staff_id;
                        $arrear->branch_id = $branch_id;
                        $arrear->region_id = $region_id;
                        $arrear->product_id = $product_id;
                        $arrear->district_id = $district_id;
                        $arrear->subcounty_id = $subcounty_id;
                        $arrear->village_id = $village_id;
                        $arrear->outsanding_principal = $csv[$i][35];
                        //this is is the interest in arrears
                        $arrear->outstanding_interest = $csv[$i][40];
                        //this is add column to the arrears table
                        $arrear->interest_in_arrears = $csv[$i][44] ?? 0;
                        //outstanding interest
                        $arrear->real_outstanding_interest = $csv[$i][36] ?? 0;
                        $arrear->principal_arrears = $csv[$i][39];
                        $arrear->number_of_days_late = $csv[$i][41];
                        $arrear->number_of_group_members = $csv[$i][47];
                        $arrear->number_of_women = $csv[$i][48];
                        $arrear->lending_type = $csv[$i][20] ?? 'Unknown';
                        $arrear->par = $csv[$i][42];
                        $arrear->gender = $csv[$i][19] ?? 'Unknown';
                        $arrear->customer_id = $customer->customer_id;
                        $arrear->amount_disbursed = $csv[$i][27];
                        $arrear->next_repayment_principal = $csv[$i][33];
                        $arrear->next_repayment_interest = $csv[$i][34];
                        $arrear->next_repayment_date = $csv[$i][32];
                        $arrear->group_id = blank($csv[$i][4]) ? $csv[$i][12] : $csv[$i][4];
                        $arrear->disbursement_date = $csv[$i][30];
                        $arrear->draw_down_balance = $csv[$i][44];
                        $arrear->savings_balance = $csv[$i][43];
                        $arrear->group_name = $csv[$i][3];
                        $arrear->maturity_date = $csv[$i][31];

                        $arrear->save();
                        if ($this->isLastDayOfMonth()) {
                            $previous_end_month = new PreviousEndMonth();
                            $previous_end_month->staff_id = $staff_id;
                            $previous_end_month->branch_id = $branch_id;
                            $previous_end_month->region_id = $region_id;
                            $previous_end_month->product_id = $product_id;
                            $previous_end_month->district_id = $district_id;
                            $previous_end_month->subcounty_id = $subcounty_id;
                            $previous_end_month->village_id = $village_id;
                            $previous_end_month->outsanding_principal = $csv[$i][35];
                            //this is is the interest in arrears
                            $previous_end_month->outstanding_interest = $csv[$i][40];
                            //this is add column to the arrears table
                            $previous_end_month->interest_in_arrears = $csv[$i][44] ?? 0;
                            $previous_end_month->principal_arrears = $csv[$i][39];
                            $previous_end_month->number_of_days_late = $csv[$i][41];
                            $previous_end_month->number_of_group_members = $csv[$i][47];
                            $previous_end_month->lending_type = $csv[$i][20] ?? 'Unknown';
                            $previous_end_month->par = $csv[$i][42];
                            $previous_end_month->gender = $csv[$i][19] ?? 'Unknown';
                            $previous_end_month->customer_id = $customer->customer_id;
                            $previous_end_month->amount_disbursed = $csv[$i][27];
                            $previous_end_month->next_repayment_principal = $csv[$i][33];
                            $previous_end_month->next_repayment_interest = $csv[$i][34];
                            $previous_end_month->next_repayment_date = $csv[$i][32];
                            $previous_end_month->group_id = blank($csv[$i][7]) ? $csv[$i][12] : $csv[$i][7];

                            $previous_end_month->save();
                        }
                    }
                }catch (\Exception $e) {
                    Log::error($e->getMessage());
                    // return response()->json(['error' => 'Failed to process CSV. Please ensure the file format is correct.', 'exception' => $e->getMessage()], 400);
                }

                //delet the csv file
                // unlink($file);

                // //send an email to nwilberforce256@gmail.com that the "file uploaded well today"
                // Mail::raw('File uploaded well today', function ($message) {
                //     $message->to('nwilberforce256@gmail.com');
                //     $message->subject('File uploaded well today');
                // });

                //file uploaded using mail, FileUploaded.php
                Mail::to('nsengiyumvawilberforce@gmail.com')->send(new FileUploaded());

                //remove the .csv
                unlink($file);
            }

            public function isLastDayOfMonth()
            {
                $today = Carbon::now();
                return $today->isLastOfMonth();
            }


    }

