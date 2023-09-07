<?php

namespace baykit\bayserver\docker\http\h2;


class HeaderBlockBuilder
{
    public function buildHeaderBlock(string $name, string $value, HeaderTable $tbl) : HeaderBlock
    {
        $idxList = $tbl->getIdxList($name);

        $blk = null;
        foreach($idxList as $idx) {
            $kv = $tbl->get($idx);
            if($kv != null && $value == $kv->value) {
                $blk = new HeaderBlock();
                $blk->op = HeaderBlock::INDEX;
                $blk->index = $idx;
                break;
            }
        }

        if($blk == null) {
            $blk = new HeaderBlock();
            if (count($idxList) > 0) {
                $blk->op = HeaderBlock::KNOWN_HEADER;
                $blk->index = $idxList[0];
                $blk->value = $value;
            } else {
                $blk->op = HeaderBlock::UNKNOWN_HEADER;
                $blk->name = $name;
                $blk->value = $value;
            }
        }

        return $blk;
    }

    public function buildStatusBlock(int $status, HeaderTable $tbl) : HeaderBlock
    {
        $stIndex = -1;

        $statusIndexList = $tbl->get(":status");
        foreach($statusIndexList as $index) {
            $kv = $tbl->get($index);
            if($kv != null &&  $status == intval($kv->value)) {
                $stIndex = $index;
                break;
            }
        }

        $blk = new HeaderBlock();
        if($stIndex != -1) {
            $blk->op = HeaderBlock::INDEX;
            $blk->index = $stIndex;
        }
        else {
            $blk->op = HeaderBlock::KNOWN_HEADER;
            $blk->index = $statusIndexList[0];
            $blk->value = strval($status);
        }

        return $blk;
    }
}

