<?php

namespace sie\parser;

use Exception;
use sie\parser\tokenizer\BeginArrayToken;
use sie\parser\tokenizer\EndArrayToken;

class BuildEntry
{

    public $line;
    public $first_token;
    public $tokens;
    public $lenient;

    public function __construct($line, tokenizer\Token $first_token, $tokens, $lenient)
    {
        $this->line = $line;
        $this->first_token = $first_token;
        $this->tokens = $tokens;
        $this->lenient = $lenient;
    }

    public function call()
    {
        if ($this->first_token->known_entry_type()) {
            return $this->build_complete_entry();
        } elseif ($this->lenient) {
            return $this->build_empty_entry();
        } else {
            $this->raise_invalid_entry_error();
        }
    }

    protected function build_complete_entry()
    {
        $entry = $this->build_empty_entry();

        $attributes_with_tokens = $this->attributes_with_tokens();

        foreach ($attributes_with_tokens as $attribute_with_tokens) {
            $attr = $attribute_with_tokens[0];
            $attr_tokens = $attribute_with_tokens[1];

            if (!is_array($attr)) {
                $label = $attr;
                $entry->attributes->$label = $attr_tokens;
            } else {
                $label = $attr["name"];
                $type = $attr["type"];
                $entry->attributes->$label = [];

                if (count($attr_tokens) > 0) {
                    $entry->attributes->$label = [];
                    // the attribute tokens are supplied pair wise, ie 1 2 3 4 -> [1=>2], [3=>4]
                    $pairs = (int) count($attr_tokens) / 2;
                    $many = isset($attr["many"]) && $attr["many"];
                    if (!$many && $pairs > 1) {
                        throw new InvalidEntryError(
                            "More than one pair of attribute tokens for $label on line: " . $this->line
                        );
                    }
                    $values = [];
                    for ($pair = 0; $pair < $pairs; $pair++) {
                        $values[$type[0]] = $attr_tokens[$pair];
                        $values[$type[1]] = $attr_tokens[$pair + 1];
                        $entry->attributes->$label[] = $values;
                    }

                } else {
                    $entry->attributes->$label = [];
                }

            }
        }

        return $entry;
    }

    protected function attributes_with_tokens()
    {
        $return = [];
        foreach ($this->line_entry_type() as $attr_entry_type) {

            $token = array_shift($this->tokens);

            if (!$token) {
                continue;
            }

            if (is_string($attr_entry_type)) {
                $return[] = [$attr_entry_type, $token->value];
            } else {
                if (!($token instanceof BeginArrayToken)) {
                    throw new InvalidEntryError("Unexpected token: " . print_r($token, true));
                }

                $hash_tokens = [];
                while ($token = array_shift($this->tokens)) {
                    if ($token instanceof EndArrayToken) {
                        break;
                    }
                    $hash_tokens[] = $token->value;
                }
                $return[] = [$attr_entry_type, $hash_tokens];
            }

        }
        return $return;

    }

    protected function build_empty_entry()
    {
        $entry = new Entry($this->first_token->label());
        return $entry;
    }

    protected function line_entry_type()
    {
        return $this->first_token->entry_type();
    }

    protected function raise_invalid_entry_error()
    {
        throw new InvalidEntryError("Unknown entry type: " . $this->first_token->label() . "");
    }

}

class InvalidEntryError extends Exception
{

}
