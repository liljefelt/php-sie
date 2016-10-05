<?php


class DocumentTest extends \Codeception\Test\Unit
{
    /**
     * @var \UnitGuy
     */
    protected $guy;

    protected function _before()
    {
        date_default_timezone_set('UTC');
        $this->sie_file = null;
    }

    protected function _after()
    {
    }

    protected $sie_file;

    protected function financial_years()
    {
        $interval = new DateInterval('P1D');
        return [
            new DatePeriod(new DateTime('2011-01-01'), $interval, new DateTime('2011-12-31')),
            new DatePeriod(new DateTime('2012-01-01'), $interval, new DateTime('2012-12-31')),
            new DatePeriod(new DateTime('2013-01-01'), $interval, new DateTime('2013-12-31')),
        ];
    }

    protected function generated_on()
    {
        return (new DateTime('2014-01-01'));
    }

    protected function accounts()
    {
        return [
            [
                "number" => 1500,
                "description" => "Customer ledger",
            ]
        ];
    }

    protected function vouchers()
    {
        return [
            [
                "creditor" => false,
                "type" => "invoice",
                "number" => 1,
                "booked_on" => (new DateTime())->setDate(2011, 9, 3),
                "description" => "Invoice 1",
                "voucher_lines" => [
                    [
                        "account_number" => 1500,
                        "amount" => 512.0,
                        "booked_on" => (new DateTime())->setDate(2011, 9, 3),
                        "description" => "Item 1",
                        "dimensions" => [6 => 1]
                    ],
                    [
                        "account_number" => 3100,
                        "amount" => -512.0,
                        "booked_on" => (new DateTime())->setDate(2011, 9, 3),
                        "description" => "Item 1",
                        "dimensions" => [6 => 1]
                    ],
                ]
            ],
            [
                "creditor" => true,
                "type" => "payment",
                "number" => 2,
                "booked_on" => (new DateTime())->setDate(2012, 8, 31),
                "description" => "Payout 1",
                "voucher_lines" => [
                    [
                        "account_number" => 2400,
                        "amount" => 256.5,
                        "description" => "Payout line 1"
                    ],
                    [
                        "account_number" => 1970,
                        "amount" => -256.5,
                    ],
                ]
            ]
        ];
    }

    protected function dimensions()
    {
        return [
            [
                "number" => 6,
                "description" => "Project",
                "objects" => [
                    ["number" => 1, "description" => "Education"]
                ]
            ]
        ];
    }

    protected function doc($contextModifyingCallback = null)
    {
        $data_source = new TestDataSource(
            [
                "accounts" => $this->accounts(),
                "vouchers" => $this->vouchers(),
                "program" => "Foonomic",
                "program_version" => "3.11",
                "generated_on" => $this->generated_on(),
                "company_name" => "Foocorp",
                "financial_years" => $this->financial_years(),
                "balance_account_numbers" => [1500, 2400, 9999],
                "closing_account_numbers" => [3100, 9999],
                "dimensions" => $this->dimensions(),
            ]
        );

        if (is_callable($contextModifyingCallback)) {
            $contextModifyingCallback($data_source);
        }

        return (new \sie\Document($data_source));
    }

    protected function set_test_sie_file($contextModifyingCallback = null)
    {
        //codecept_debug(__METHOD__);

        $doc = $this->doc($contextModifyingCallback);

        //codecept_debug($doc);
        //codecept_debug("render result: '" . $doc->render() . "'");

        $this->sie_file = (new \sie\Parser())->parse($doc->render());

        //codecept_debug($this->sie_file);
    }

    // tests

    public function testAddsAHeader()
    {
        $this->set_test_sie_file();
        $this->assertEquals("0", $this->entry_attribute("flagga", "x"));
        $this->assertEquals("Foonomic", $this->entry_attribute("program", "programnamn"));
        $this->assertEquals("3.11", $this->entry_attribute("program", "version"));
        $this->assertEquals("PC8", $this->entry_attribute("format", "PC8"));
        $this->assertEquals($this->generated_on()->format("Ymd"), $this->entry_attribute("gen", "datum"));
        $this->assertEquals("4", $this->entry_attribute("sietyp", "typnr"));
        $this->assertEquals("Foocorp", $this->entry_attribute("fnamn", "foretagsnamn"));
    }

    public function testHasAccountingYears()
    {
        $this->set_test_sie_file();
        $this->assertEquals("0", $this->indexed_entry_attribute("rar", 0, "arsnr"));
        $this->assertEquals("20130101", $this->indexed_entry_attribute("rar", 0, "start"));
        $this->assertEquals("20131231", $this->indexed_entry_attribute("rar", 0, "slut"));
        $this->assertEquals("-1", $this->indexed_entry_attribute("rar", 1, "arsnr"));
        $this->assertEquals("20120101", $this->indexed_entry_attribute("rar", 1, "start"));
        $this->assertEquals("20121231", $this->indexed_entry_attribute("rar", 1, "slut"));
        $this->assertEquals("-2", $this->indexed_entry_attribute("rar", 2, "arsnr"));
        $this->assertEquals("20110101", $this->indexed_entry_attribute("rar", 2, "start"));
        $this->assertEquals("20111231", $this->indexed_entry_attribute("rar", 2, "slut"));
    }

    public function testHasAccounts()
    {
        $this->set_test_sie_file();
        $this->assertEquals(
            (object) ["kontonr" => "1500", "kontonamn" => "Customer ledger"],
            $this->indexed_entry_attributes("konto", 0)
        );
    }

    public function testHasDimensions()
    {
        $this->set_test_sie_file();
        $this->assertEquals(
            (object) ["dimensionsnr" => "6", "namn" => "Project"],
            $this->indexed_entry_attributes("dim", 0)
        );
    }

    public function testHasObjects()
    {
        $this->set_test_sie_file();
        $this->assertEquals(
            (object) ["dimensionsnr" => "6", "objektnr" => "1", "objektnamn" => "Education"],
            $this->indexed_entry_attributes("objekt", 0)
        );
    }

    // ingående balans
    public function testHasBalancesBroughtForward()
    {
        $this->set_test_sie_file();
        $this->assertNotEquals(
            (object) ["arsnr" => "0", "konto" => "9999", "saldo" => ""],
            $this->indexed_entry_attributes("ib", 0)
        );
        $this->assertEquals(
            (object) ["arsnr" => "0", "konto" => "1500", "saldo" => "1600.0"],
            $this->indexed_entry_attributes("ib", 0)
        );
        $this->assertEquals(
            (object) ["arsnr" => "0", "konto" => "2400", "saldo" => "2500.0"],
            $this->indexed_entry_attributes("ib", 1)
        );
        $this->assertEquals(
            (object) ["arsnr" => "-1", "konto" => "1500", "saldo" => "1600.0"],
            $this->indexed_entry_attributes("ib", 2)
        );
        $this->assertEquals(
            (object) ["arsnr" => "-1", "konto" => "2400", "saldo" => "2500.0"],
            $this->indexed_entry_attributes("ib", 3)
        );
        $this->assertEquals(
            (object) ["arsnr" => "-2", "konto" => "1500", "saldo" => "1600.0"],
            $this->indexed_entry_attributes("ib", 4)
        );
        $this->assertEquals(
            (object) ["arsnr" => "-2", "konto" => "2400", "saldo" => "2500.0"],
            $this->indexed_entry_attributes("ib", 5)
        );

    }

    // utgående balans
    public function testHasBalancesCarriedForward()
    {
        $this->set_test_sie_file();
        $this->assertNotEquals(
            (object) ["arsnr" => "0", "konto" => "9999", "saldo" => ""],
            $this->indexed_entry_attributes("ub", 0)
        );
        $this->assertEquals(
            (object) ["arsnr" => "0", "konto" => "1500", "saldo" => "4603.64"],
            $this->indexed_entry_attributes("ub", 0)
        );
        $this->assertEquals(
            (object) ["arsnr" => "0", "konto" => "2400", "saldo" => "5503.64"],
            $this->indexed_entry_attributes("ub", 1)
        );
        $this->assertEquals(
            (object) ["arsnr" => "-1", "konto" => "1500", "saldo" => "4603.65"],
            $this->indexed_entry_attributes("ub", 2)
        );
        $this->assertEquals(
            (object) ["arsnr" => "-1", "konto" => "2400", "saldo" => "5503.65"],
            $this->indexed_entry_attributes("ub", 3)
        );
        $this->assertEquals(
            (object) ["arsnr" => "-2", "konto" => "1500", "saldo" => "4603.64"],
            $this->indexed_entry_attributes("ub", 4)
        );
        $this->assertEquals(
            (object) ["arsnr" => "-2", "konto" => "2400", "saldo" => "5503.64"],
            $this->indexed_entry_attributes("ub", 5)
        );

    }

    // saldo för resultatkonto
    public function testHasClosingAccountBalances()
    {
        $this->set_test_sie_file();
        $this->assertNotEquals(
            (object) ["ars" => "0", "konto" => "9999", "saldo" => ""],
            $this->indexed_entry_attributes("res", 0)
        );
        $this->assertEquals(
            (object) ["ars" => "0", "konto" => "3100", "saldo" => "6203.64"],
            $this->indexed_entry_attributes("res", 0)
        );
        $this->assertEquals(
            (object) ["ars" => "-1", "konto" => "3100", "saldo" => "6203.65"],
            $this->indexed_entry_attributes("res", 1)
        );
        $this->assertEquals(
            (object) ["ars" => "-2", "konto" => "3100", "saldo" => "6203.64"],
            $this->indexed_entry_attributes("res", 2)
        );
    }

    public function testHasVouchers()
    {
        $this->set_test_sie_file();
        $this->assertEquals(
            (object) [
                "serie" => "KF",
                "vernr" => "1",
                "verdatum" => "20110903",
                "vertext" => "Invoice 1"
            ],
            $this->indexed_entry("ver", 0)->attributes
        );
        $this->assertEquals(
            (object) [
                "kontonr" => "1500",
                "belopp" => "512.0",
                "transdat" => "20110903",
                "transtext" => "Item 1",
                "objektlista" => [["dimensionsnr" => "6", "objektnr" => "1"]]
            ],
            $this->indexed_voucher_entries(0)[0]->attributes
        );
        $this->assertEquals(
            (object) [
                "kontonr" => "3100",
                "belopp" => "-512.0",
                "transdat" => "20110903",
                "transtext" => "Item 1",
                "objektlista" => [["dimensionsnr" => "6", "objektnr" => "1"]]
            ],
            $this->indexed_voucher_entries(0)[1]->attributes
        );

        $this->assertEquals(
            (object) [
                "serie" => "KB",
                "vernr" => "2",
                "verdatum" => "20120831",
                "vertext" => "Payout 1"
            ],
            $this->indexed_entry("ver", 1)->attributes
        );
        $this->assertEquals(
            (object) [
                "kontonr" => "2400",
                "belopp" => "256.5",
                "transdat" => "",
                "transtext" => "Payout line 1",
                "objektlista" => []
            ],
            $this->indexed_voucher_entries(1)[0]->attributes
        );
        $this->assertEquals(
            (object) [
                "kontonr" => "1970",
                "belopp" => "-256.5",
                "objektlista" => []
            ],
            $this->indexed_voucher_entries(1)[1]->attributes
        );

    }

    public function testTruncatesReallyLongDescriptions()
    {
        $this->set_test_sie_file(
            function (TestDataSource &$data_source) {
                // with really long descriptions
                $data_source->accounts = [
                    [
                        "number" => 1500,
                        # Make sure that the description exceeds the limit (100 chars).
                        "description" => str_repeat("k", 101)
                    ]
                ];
                $data_source->vouchers = [
                    $this->build_voucher(
                        [
                            "description" => str_repeat("d", 101),
                            "voucher_lines" => [
                                $this->build_voucher_line(["description" => str_repeat("v", 101)]),
                                $this->build_voucher_line(["description" => "Payout line 2"]),
                            ]
                        ]
                    )
                ];
            }
        );

        $this->assertEquals(
            (object) ["kontonr" => "1500", "kontonamn" => str_repeat("k", 100)],
            $this->indexed_entry_attributes("konto", 0)
        );
        $this->assertEquals(str_repeat("d", 100), $this->indexed_entry("ver", 0)->attributes->vertext);
        $this->assertEquals(str_repeat("v", 100), $this->indexed_voucher_entries(0)[0]->attributes->transtext);
    }

    public function testEnsuresThereAreAtLeastTwoLinesWithAZeroedSingleVoucherLine()
    {
        $this->set_test_sie_file(
            function (TestDataSource &$data_source) {
                // with a zeroed single voucher line
                $data_source->vouchers = [
                    $this->build_voucher(
                        [
                            "voucher_lines" => [
                                $this->build_voucher_line(["amount" => 0])
                            ]
                        ]
                    )
                ];
            }
        );

        $this->assertEquals(2, count($this->indexed_voucher_entries(0)));
    }

    public function testReadsTheSeriesFromTheVoucherWithASeriesDefined()
    {
        $this->set_test_sie_file(
            function (TestDataSource &$data_source) {
                // with a series defined
                $data_source->vouchers = [
                    $this->build_voucher(
                        [
                            "series" => "X"
                        ]
                    )
                ];
            }
        );

        $this->assertEquals("X", $this->indexed_entry("ver", 0)->attributes->serie);
    }

    public function testExportedFileMatchesSieFileFixture()
    {
        $sieFilePath = codecept_data_dir('fixtures/exported_sie_file.se');
        $actual = $this->doc()->render();
        $expected = file_get_contents($sieFilePath);
        $this->assertEquals($expected, $actual);
    }

    protected function build_voucher($attributes)
    {
        $defaults = [
            "creditor" => true,
            "type" => "payment",
            "number" => 1,
            "booked_on" => new DateTime(),
            "description" => "A voucher",
            "voucher_lines" => [
                $this->build_voucher_line(),
                $this->build_voucher_line(),
            ],
        ];
        return array_merge($defaults, $attributes);
    }

    protected function build_voucher_line($attributes = [])
    {
        $defaults = [
            "account_number" => 1234,
            "amount" => 1,
            "booked_on" => DateTime::createFromFormat("Ymd", (new DateTime())->format("Ymd")),
            "description" => "A voucher line"
        ];
        return array_merge($defaults, $attributes);
    }

    protected function entry_attribute($label, $attribute)
    {
        return $this->indexed_entry_attribute($label, 0, $attribute);
    }

    protected function indexed_entry_attribute($label, $index, $attribute)
    {
        $attributes = $this->indexed_entry_attributes($label, $index);
        if (!isset($attributes->$attribute)) {
            throw new Exception("Unknown attribute $attribute in " . print_r(get_class_methods($attributes), true));
        }
        return $attributes->$attribute;
    }

    protected function indexed_entry_attributes($label, $index)
    {
        return $this->indexed_entry($label, $index)->attributes;
    }

    protected function indexed_voucher_entries($index)
    {
        return $this->indexed_entry("ver", $index)->entries;
    }

    protected function indexed_entry($label, $index)
    {
        $entries_with_label = $this->sie_file->entries_with_label($label);
        if (!array_key_exists($index, $entries_with_label)) {
            throw new Exception("No entry with label " . print_r($label, true) . " found!");
        }
        return $entries_with_label[$index];
    }

}

class TestDataSource extends \sie\document\DataSource
{
    function balance_before($account_number, \DateTime $date)
    {
        if ($account_number == 9999) {
            # So we can test empty balances.
            return null;
        } else {
            # Faking a fetch based on date and number.
            return (double) $account_number + ($date->format("j") * 100) + ($date->format("z") / 100);
        }
    }
}
