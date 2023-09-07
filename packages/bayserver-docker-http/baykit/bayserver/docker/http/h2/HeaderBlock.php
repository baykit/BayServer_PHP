<?php

namespace baykit\bayserver\docker\http\h2;


class HeaderBlock
{
    const INDEX = 1;
    const OVERLOAD_KNOWN_HEADER = 2;
    const NEW_HEADER = 3;
    const KNOWN_HEADER = 4;
    const UNKNOWN_HEADER = 5;
    const UPDATE_DYNAMIC_TABLE_SIZE = 6;

    public $op;
    public $index;
    public $name;
    public $value;
    public $size;

    public function __toString()
    {
        return "{$this->op} index={$this->index} name={$this->name} value={$this->value} size={$this->size}";
    }

    public static function pack(HeaderBlock $blk, H2DataAccessor $acc): void
    {
        switch ($blk->op) {
            case self::INDEX:
            {
                $acc->putHPackInt($blk->index, 7, 1);
                break;
            }
            case self::OVERLOAD_KNOWN_HEADER:
            {
                throw new \InvalidArgumentException();
            }
            case self::NEW_HEADER:
            {
                throw new \InvalidArgumentException();
            }
            case self::KNOWN_HEADER:
            {
                $acc->putHPackInt($blk->index, 4, 0);
                $acc->putHPackString($blk->value, false);
                break;
            }
            case self::UNKNOWN_HEADER:
            {
                $acc->putByte(0);
                $acc->putHPackString($blk->name, false);
                $acc->putHPackString($blk->value, false);
                break;
            }
            case self::UPDATE_DYNAMIC_TABLE_SIZE:
            {
                throw new \InvalidArgumentException();
            }
        }
    }


    public static function unpack(H2DataAccessor $acc): HeaderBlock
    {
        $blk = new HeaderBlock();
        $index = $acc->getByte();
        $indexHeaderField = ($index & 0x80) != 0;
        if ($indexHeaderField) {
            // index header field
            /**
             *   0   1   2   3   4   5   6   7
             * +---+---+---+---+---+---+---+---+
             * | 1 |        Index (7+)         |
             * +---+---------------------------+
             */
            $blk->op = self::INDEX;
            $blk->index = $index & 0x7F;
        } else {
            // literal header field
            $updateIndex = ($index & 0x40) != 0;
            if ($updateIndex) {
                $index = $index & 0x3F;
                $overloadIndex = $index != 0;
                if ($overloadIndex) {
                    // known header name
                    if ($index == 0x3F) {
                        $index = $index + $acc->getHPackIntRest();
                    }
                    $blk->op = self::OVERLOAD_KNOWN_HEADER;
                    $blk->index = $index;

                    /**
                     *      0   1   2   3   4   5   6   7
                     *    +---+---+---+---+---+---+---+---+
                     *    | 0 | 1 |      Index (6+)       |
                     *    +---+---+-----------------------+
                     *    | H |     Value Length (7+)     |
                     *    +---+---------------------------+
                     *    | Value String (Length octets)  |
                     *    +-------------------------------+
                     */
                    $blk->value = $acc->getHPackString();
                } else {
                    // new header name
                    /**
                     *   0   1   2   3   4   5   6   7
                     * +---+---+---+---+---+---+---+---+
                     * | 0 | 1 |           0           |
                     * +---+---+-----------------------+
                     * | H |     Name Length (7+)      |
                     * +---+---------------------------+
                     * |  Name String (Length octets)  |
                     * +---+---------------------------+
                     * | H |     Value Length (7+)     |
                     * +---+---------------------------+
                     * | Value String (Length octets)  |
                     * +-------------------------------+
                     */
                    $blk->op = self::NEW_HEADER;
                    $blk->name = $acc->getHPackString();
                    $blk->value = $acc->getHPackString();
                }
            } else {
                $updateDynamicTableSize = ($index & 0x20) != 0;
                if ($updateDynamicTableSize) {
                    /**
                     *   0   1   2   3   4   5   6   7
                     * +---+---+---+---+---+---+---+---+
                     * | 0 | 0 | 1 |   Max size (5+)   |
                     * +---+---------------------------+
                     */
                    $size = $index & 0x1f;
                    if ($size == 0x1f) {
                        $size = $size + $acc->getHPackIntRest();
                    }
                    $blk->op = self::UPDATE_DYNAMIC_TABLE_SIZE;
                    $blk->size = $size;
                } else {
                    // not update index
                    $index = ($index & 0xF);
                    if ($index != 0) {
                        /**
                         *   0   1   2   3   4   5   6   7
                         * +---+---+---+---+---+---+---+---+
                         * | 0 | 0 | 0 | 0 |  Index (4+)   |
                         * +---+---+-----------------------+
                         * | H |     Value Length (7+)     |
                         * +---+---------------------------+
                         * | Value String (Length octets)  |
                         * +-------------------------------+
                         *
                         * OR
                         *
                         *   0   1   2   3   4   5   6   7
                         * +---+---+---+---+---+---+---+---+
                         * | 0 | 0 | 0 | 1 |  Index (4+)   |
                         * +---+---+-----------------------+
                         * | H |     Value Length (7+)     |
                         * +---+---------------------------+
                         * | Value String (Length octets)  |
                         * +-------------------------------+
                         */
                        if ($index == 0xF) {
                            $index = $index + $acc->getHPackIntRest();
                        }
                        $blk->op = self::KNOWN_HEADER;
                        $blk->index = $index;
                        $blk->value = $acc->getHPackString();
                    } else {
                        // literal header field
                        /**
                         *   0   1   2   3   4   5   6   7
                         * +---+---+---+---+---+---+---+---+
                         * | 0 | 0 | 0 | 0 |       0       |
                         * +---+---+-----------------------+
                         * | H |     Name Length (7+)      |
                         * +---+---------------------------+
                         * |  Name String (Length octets)  |
                         * +---+---------------------------+
                         * | H |     Value Length (7+)     |
                         * +---+---------------------------+
                         * | Value String (Length octets)  |
                         * +-------------------------------+
                         *
                         * OR
                         *
                         *   0   1   2   3   4   5   6   7
                         * +---+---+---+---+---+---+---+---+
                         * | 0 | 0 | 0 | 1 |       0       |
                         * +---+---+-----------------------+
                         * | H |     Name Length (7+)      |
                         * +---+---------------------------+
                         * |  Name String (Length octets)  |
                         * +---+---------------------------+
                         * | H |     Value Length (7+)     |
                         * +---+---------------------------+
                         * | Value String (Length octets)  |
                         * +-------------------------------+
                         */
                        $blk->op = self::UNKNOWN_HEADER;
                        $blk->name = $acc->getHPackString();
                        $blk->value = $acc->getHPackString();
                    }
                }
            }
        }
        return $blk;
    }
}

