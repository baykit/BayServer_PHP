<?php
namespace baykit\bayserver\bcf\BcfParser;
class LineInfo
{
    public $lineObj;
    public $indent;

    public function __construct($lineObj, $indent)
    {
        $this->lineObj = $lineObj;
        $this->indent = $indent;
    }
}

namespace baykit\bayserver\bcf;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\StringUtil;

class BcfParser
{
    public $fileName;
    public $lineNo;
    public $input;
    public $prevLineInfo;
    public $indentMap;

    public function parse($file) : BcfDocument
    {
        $doc = new BcfDocument();
        $this->fileName = $file;
        $this->lineNo = 0;
        $this->prevLineInfo = NULL;
        $this->indentMap = [];

        #$enc = mb_internal_encoding();
        $enc = NULL;
        if (!$enc)
            $enc = "utf-8";
        $this->input = fopen($file, "r");
        $this->parseSameLevel($doc->contentList, 0);
        fclose($this->input);
        return $doc;
    }

    private function pushIndent(int $sp_count)
    {
        $this->indentMap[] = $sp_count;
    }

    private function popIndent()
    {
        array_pop($this->indentMap);
    }

    private function getIndent(int $sp_count) : int
    {
        if (count($this->indentMap) == 0)
            $this->pushIndent($sp_count);
        elseif ($sp_count > $this->indentMap[count($this->indentMap) - 1])
            $this->pushIndent($sp_count);

        $indent = array_search($sp_count, $this->indentMap);
        if($indent === false)
            throw new ParseException($this->fileName, $this->lineNo, BayMessage::get(Symbol::PAS_INVALID_INDENT));

        return $indent;
    }

    private function parseSameLevel(&$cur_list, $indent)
    {
        $objectExistsInSameLevel = false;
        while(true) {
            if ($this->prevLineInfo !== null) {
                $lineInfo = $this->prevLineInfo;
                $this->prevLineInfo = NULL;
            }
            else {

                $line = fgets($this->input);
                $this->lineNo += 1;

                if ($line == "")
                    break;

                if (trim($line) == "" or StringUtil::startsWith(trim($line), "#"))
                    continue;

                $lineInfo = $this->parseLine($this->lineNo, $line);

            }

            if (!$lineInfo)
                # Comment or empty
                continue;

            elseif ($lineInfo->indent > $indent)
                # lower level
                throw new ParseException($this->fileName, $this->lineNo, BayMessage::get(Symbol::PAS_INVALID_INDENT));

            elseif ($lineInfo->indent < $indent) {
                # upper level
                $this->prevLineInfo = $lineInfo;
                if ($objectExistsInSameLevel)
                    $this->popIndent();

                return $lineInfo;
            }

            else {
                $objectExistsInSameLevel = true;

                # samel level
                if ($lineInfo->lineObj instanceof BcfElement) {
                    # BcfElement
                    $cur_list[] = $lineInfo->lineObj;

                    $lastLineInfo = $this->parseSameLevel($lineInfo->lineObj->contentList, $lineInfo->indent + 1);
                    if (!$lastLineInfo) {
                        # EOF
                        $this->popIndent();
                        return false;
                    } else {
                        # Same level
                        continue;
                    }
                }
                else {
                    # IniKeyVal
                    $cur_list[] = $lineInfo->lineObj;
                }
            }
        }

        $this->popIndent();
        return false;
    }

    private function parseLine(int $lineNo, string $line) : BcfParser\LineInfo
    {
        for ($sp_count = 0; $sp_count <  strlen($line); $sp_count++) {

            $c = $line[$sp_count];
            if (trim($c) != '') {
                # c is not awhitespace
                break;
            }

            if ($c != ' ')
                throw new ParseException($this->fileName, $this->lineNo, BayMessage::get(Symbol::PAS_INVALID_WHITESPACE));
        }

        $indent = $this->getIndent($sp_count);
        $line = substr($line, $sp_count);
        $line = trim($line);

        if (StringUtil::startsWith($line, "[")) {

            $close_pos = strpos($line, "]");
            if ($close_pos === false)
                throw new ParseException($this->fileName, $this->lineNo, BayMessage::get(Symbol::PAS_BRACE_NOT_CLOSED));

            if (!StringUtil::endsWith($line, "]"))
                throw new  ParseException($this->fileName, $this->lineNo, BayMessage::get(Symbol::PAS_INVALID_LINE));

            $key_val = $this->parseKeyVal(substr($line, 1, $close_pos - 1), $lineNo);
            return new BcfParser\LineInfo(new BcfElement($key_val->key, $key_val->value, $this->fileName, $lineNo), $indent);
        }
        else
            return new BcfParser\LineInfo($this->parseKeyVal($line, $lineNo), $indent);
    }

    public function parseKeyVal(string $line, int $lineNo) {

        $sp_pos = strpos($line, ' ');
        if($sp_pos === false) {
            $key = $line;
            $val = "";
        }
        else {
            $key = substr($line, 0, $sp_pos);
            $val = trim(substr($line, $sp_pos));
        }

        return new BcfKeyVal($key, $val, $this->fileName, $lineNo);
    }
}