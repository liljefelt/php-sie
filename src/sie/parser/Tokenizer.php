<?php

namespace sie\parser;

use StrScan\StringScanner;
use sie\Parser;
use sie\parser\tokenizer\EntryToken;
use sie\parser\tokenizer\BeginArrayToken;
use sie\parser\tokenizer\EndArrayToken;
use sie\parser\tokenizer\StringToken;
use Exception;

class Tokenizer
{

    protected $line;
    protected $scanner;

    public function __construct($line)
    {
        $this->line = $line;
        $this->scanner = new StringScanner($line);
    }

    public function tokenize()
    {
        $tokens = [];
        $this->check_for_control_characters();

        while (!$this->scanner->hasTerminated()) {

            if ($this->whitespace() !== null) {
                continue;
            } elseif (($match = $this->find_entry()) !== null) {
                $tokens[] = new EntryToken($match);
            } elseif ($this->begin_array() !== null) {
                $tokens[] = new BeginArrayToken();
            } elseif ($this->end_array() !== null) {
                $tokens[] = new EndArrayToken();
            } elseif (($match = $this->find_string()) !== null) {
                $tokens[] = new StringToken($match);
            } elseif ($this->end_of_string()) {
                break;
            } else {

                # We shouldn't get here, but if we do we need to bail out, otherwise we get an infinite loop.
                throw new Exception(
                    "Unhandled character in line at position #"
                    . $this->scanner->getPosition()
                    . ": '" . $this->scanner->getSource() . "' at '" . $this->scanner->getRemainder() . "'"
                );

            }

        }

        return $tokens;
    }

    protected function check_for_control_characters()
    {
        if ($match = preg_match('/(.*?)([\\x00-\\x08\\x0a-\\x1f\\x7f])/', $this->line)) {
            throw new Exception(
                "Unhandled control character in line at position #"
                . (strlen($match) + 1)
                . ": " . $this->scanner->getRemainder()
            );
        }
    }

    protected function whitespace()
    {
        return $this->scanner->scan('/[ \t]+/');
    }

    protected function find_entry()
    {
        $match = $this->scanner->scan('/#\S+/');

        if ($match) {
            return preg_replace('/#/', "", $match);
        } else {
            return null;
        }
    }

    protected function begin_array()
    {
        return $this->scanner->scan('/' . Parser::BEGINNING_OF_ARRAY . '/');
    }


    protected function end_array()
    {
        return $this->scanner->scan('/' . Parser::END_OF_ARRAY . '/');
    }

    protected function find_string()
    {
        $match = $this->find_quoted_string();
        if ($match === null) {
            $match = $this->find_unquoted_string();
        }

        if ($match !== null) {
            return $this->remove_unnecessary_escapes($match);
        } else {
            return null;
        }
    }

    protected function end_of_string()
    {
        return $this->scanner->hasTerminated();
    }

    protected function find_quoted_string()
    {
        $match = $this->scanner->scan('/"(\\\\"|[^"])*"/');

        if ($match) {
            return preg_replace('/"$/', '', preg_replace('/^"/', '', $match));
        } else {
            return null;
        }
    }

    protected function find_unquoted_string()
    {
        return $this->scanner->scan('/(\\\\"|[^"{}\s])+/');
    }

    protected function remove_unnecessary_escapes($match)
    {
        return preg_replace('/\\\\([\\\\"])/', "$1", $match);
    }

}
