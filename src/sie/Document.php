<?php

namespace sie;

use sie\document\DataSource;
use sie\document\VoucherSeries;
use sie\document\Renderer;

class Document
{

    # Because some accounting software have limits
    #  - Fortnox should handle 200
    #  - Visma etc -> 100
    const DESCRIPTION_LENGTH_MAX = 100;

    /** @var DataSource */
    public $data_source;

    public function __construct(DataSource $data_source)
    {
        $this->data_source = $data_source;
    }

    public function render()
    {
        $this->renderer = null;
        $this->add_header();
        $this->add_financial_years();
        $this->add_accounts();
        $this->add_dimensions();
        $this->add_balances();
        $this->add_vouchers();
        return $this->renderer()->render();
    }

    private function add_header()
    {
        $this->renderer()->add_line("FLAGGA", [0]);
        $this->renderer()->add_line("PROGRAM", [$this->data_source->program(), $this->data_source->program_version()]);
        $this->renderer()->add_line("FORMAT", ["PC8"]);
        $this->renderer()->add_line("GEN", [$this->data_source->generated_on()]);
        $this->renderer()->add_line("SIETYP", [4]);
        $this->renderer()->add_line("FNAMN", [$this->data_source->company_name()]);
    }

    private function add_financial_years()
    {
        /**
         * @var \DatePeriod $date_range
         */
        foreach ($this->financial_years() as $index => $date_range) {
            $this->renderer()->add_line("RAR", [-$index, $date_range->getStartDate(), $date_range->getEndDate()]);
        }
    }

    private function add_accounts()
    {
        foreach ($this->data_source->accounts() as $account) {
            $number = $account["number"];
            $description = iconv_substr($account["description"], 0, static::DESCRIPTION_LENGTH_MAX, "UTF-8");
            $this->renderer()->add_line("KONTO", [$number, $description]);
        }
    }

    private function add_balances()
    {
        /**
         * @var \DatePeriod $date_range
         */
        foreach ($this->financial_years() as $index => $date_range) {
            $this->add_balance_rows(
                "IB",
                -$index,
                $this->data_source->balance_account_numbers(),
                $date_range->getStartDate()
            );
            $this->add_balance_rows(
                "UB",
                -$index,
                $this->data_source->balance_account_numbers(),
                $date_range->getEndDate()
            );
            $this->add_balance_rows(
                "RES",
                -$index,
                $this->data_source->closing_account_numbers(),
                $date_range->getEndDate()
            );
        }
    }

    private function add_balance_rows($label, $year_index, $account_numbers, $date)
    {
        foreach ($account_numbers as $account_number) {
            $balance = $this->data_source->balance_before($account_number, $date);

            # Accounts with no balance should not be in the SIE-file.
            # See paragraph 5.17 in the SIE file format guide (Rev. 4B).
            if (!$balance) {
                continue;
            }

            $this->renderer()->add_line($label, [$year_index, $account_number, $balance]);
        }
    }

    private function add_dimensions()
    {
        foreach ($this->data_source->dimensions() as $dimension) {

            $dimension_number = $dimension["number"];
            $dimension_description = $dimension["description"];
            $this->renderer()->add_line("DIM", [$dimension_number, $dimension_description]);

            foreach ($dimension["objects"] as $object) {
                $object_number = $object["number"];
                $object_description = $object["description"];
                $this->renderer()->add_line("OBJEKT", [$dimension_number, $object_number, $object_description]);
            }
        }
    }

    private function add_vouchers()
    {
        foreach ($this->data_source->vouchers() as $voucher) {
            $this->add_voucher($voucher);
        }
    }

    private function add_voucher($opts)
    {

        $number = $opts["number"];
        $booked_on = $opts["booked_on"];
        $description = iconv_substr($opts["description"], 0, static::DESCRIPTION_LENGTH_MAX, "UTF-8");
        $voucher_lines = $opts["voucher_lines"];
        if (array_key_exists("series", $opts)) {
            $voucher_series = $opts["series"];
        } else {
            $creditor = $opts["creditor"];
            $type = $opts["type"];
            $voucher_series = (new VoucherSeries())->self_for($creditor, $type);
        }

        $this->renderer()->add_line("VER", [$voucher_series, $number, $booked_on, $description]);

        $this->renderer()->add_beginning_of_array();

        foreach ($voucher_lines as $line) {
            $account_number = $line["account_number"];
            $amount = $line["amount"];
            $booked_on = $line["booked_on"];
            if (array_key_exists("dimensions", $line)) {
                $dimensions = $line["dimensions"];
            } else {
                $dimensions = [];
            }

            # Some SIE-importers (fortnox) cannot handle descriptions longer than 200 characters,
            # but the specification has no limit.
            $description = iconv_substr($line["description"], 0, static::DESCRIPTION_LENGTH_MAX, "UTF-8");

            $this->renderer()->add_line("TRANS", [$account_number, $dimensions, $amount, $booked_on, $description]);

            # Some consumers of SIE cannot handle single voucher lines (fortnox), so add another empty one to make
            # it balance. The spec just requires the sum of lines to be 0, so single lines with zero amount would conform,
            # but break for these implementations.
            if (count($voucher_lines) < 2 && $amount === 0) {
                $this->renderer()->add_line("TRANS", [$account_number, $dimensions, $amount, $booked_on, $description]);
            }

        }

        $this->renderer()->add_end_of_array();

    }

    /** @var Renderer */
    private $renderer;

    private function renderer()
    {
        if (!$this->renderer) {
            $this->renderer = new Renderer();
        }
        return $this->renderer;
    }

    private function financial_years()
    {
        $financial_years = $this->data_source->financial_years();

        if (empty($financial_years)) {
            return [];
        }

        usort(
            $financial_years,
            function (\DatePeriod $fy1, \DatePeriod $fy2) {
                return $fy1->getStartDate() > $fy2->getStartDate();
            }
        );

        return array_reverse($financial_years);
    }

}
