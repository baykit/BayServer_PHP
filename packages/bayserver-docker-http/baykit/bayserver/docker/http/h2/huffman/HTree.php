<?php

namespace baykit\bayserver\docker\http\h2\huffman;

class HTree
{
    static $root; // HNode

    public static function decode(string &$data) : string
    {
        $w = "";
        $cur = self::$root;
        for ($i = 0; $i < strlen($data); $i++) {
            for ($j = 0; $j < 8; $j++) {
                $bit = ord($data[$i]) >> (8 - $j - 1) & 0x1;

                // down tree
                if ($bit == 1) {
                    $cur = $cur->one;
                }
                else {
                    $cur = $cur->zero;
                }

                if ($cur->value >= 0) {
                    // leaf node
                    $w .= chr($cur->value);
                    $cur = self::$root;
                }
            }
        }
        return $w;
    }

    static function insert(int $code, int $lenInBits, int $sym) : void
    {
        $bits = [];
        for ($i = 0; $i < $lenInBits; $i++) {
            $bits[$i] = $code >> ($lenInBits - $i - 1) & 0x1;
        }
        self::insert_bits($bits, $sym);
    }

    static function insert_bits(array &$code, $sym) {
        $curNode = self::$root;
        for($i = 0; $i < count($code); $i++) {
            if($code[$i] == 1) {
                if($curNode->one == null) {
                    $curNode->one = new HNode();
                }
                $curNode = $curNode->one;
            }
            else {
                if($curNode->zero == null) {
                    $curNode->zero = new HNode();
                }
                $curNode = $curNode->zero;
            }
        }
        $curNode->value = $sym;
    }
}


HTree::$root = new HNode();

HTree::insert(0x1ff8,13,0);
HTree::insert(0x7fffd8,23,1);
HTree::insert(0xfffffe2,28,2);
HTree::insert(0xfffffe3,28,3);
HTree::insert(0xfffffe4,28,4);
HTree::insert(0xfffffe5,28,5);
HTree::insert(0xfffffe6,28,6);
HTree::insert(0xfffffe7,28,7);
HTree::insert(0xfffffe8,28,8);
HTree::insert(0xffffea,24,9);
HTree::insert(0x3ffffffc,30,10);
HTree::insert(0xfffffe9,28,11);
HTree::insert(0xfffffea,28,12);
HTree::insert(0x3ffffffd,30,13);
HTree::insert(0xfffffeb,28,14);
HTree::insert(0xfffffec,28,15);
HTree::insert(0xfffffed,28,16);
HTree::insert(0xfffffee,28,17);
HTree::insert(0xfffffef,28,18);
HTree::insert(0xffffff0,28,19);
HTree::insert(0xffffff1,28,20);
HTree::insert(0xffffff2,28,21);
HTree::insert(0x3ffffffe,30,22);
HTree::insert(0xffffff3,28,23);
HTree::insert(0xffffff4,28,24);
HTree::insert(0xffffff5,28,25);
HTree::insert(0xffffff6,28,26);
HTree::insert(0xffffff7,28,27);
HTree::insert(0xffffff8,28,28);
HTree::insert(0xffffff9,28,29);
HTree::insert(0xffffffa,28,30);
HTree::insert(0xffffffb,28,31);
HTree::insert(0x14,6,32);
HTree::insert(0x3f8,10,33);
HTree::insert(0x3f9,10,34);
HTree::insert(0xffa,12,35);
HTree::insert(0x1ff9,13,36);
HTree::insert(0x15,6,37);
HTree::insert(0xf8,8,38);
HTree::insert(0x7fa,11,39);
HTree::insert(0x3fa, 10, 40);
HTree::insert(0x3fb,10,41);
HTree::insert(0xf9,8,42);
HTree::insert(0x7fb,11,43);
HTree::insert(0xfa,8,44);
HTree::insert(0x16,6,45);
HTree::insert(0x17,6,46);
HTree::insert(0x18,6,47);
HTree::insert(0x0,5,48);
HTree::insert(0x1,5,49);
HTree::insert(0x2,5,50);
HTree::insert(0x19,6,51);
HTree::insert(0x1a,6,52);
HTree::insert(0x1b,6,53);
HTree::insert(0x1c,6,54);
HTree::insert(0x1d,6,55);
HTree::insert(0x1e,6,56);
HTree::insert(0x1f,6,57);
HTree::insert(0x5c,7,58);
HTree::insert(0xfb,8,59);
HTree::insert(0x7ffc,15,60);
HTree::insert(0x20,6,61);
HTree::insert(0xffb,12,62);
HTree::insert(0x3fc,10,63);
HTree::insert(0x1ffa,13,64);
HTree::insert(0x21,6,65);
HTree::insert(0x5d,7,66);
HTree::insert(0x5e,7,67);
HTree::insert(0x5f,7,68);
HTree::insert(0x60,7,69);
HTree::insert(0x61,7,70);
HTree::insert(0x62,7,71);
HTree::insert(0x63,7,72);
HTree::insert(0x64,7,73);
HTree::insert(0x65,7,74);
HTree::insert(0x66,7,75);
HTree::insert(0x67,7,76);
HTree::insert(0x68,7,77);
HTree::insert(0x69,7,78);
HTree::insert(0x6a,7,79);
HTree::insert(0x6b,7,80);
HTree::insert(0x6c,7,81);
HTree::insert(0x6d,7,82);
HTree::insert(0x6e,7,83);
HTree::insert(0x6f,7,84);
HTree::insert(0x70,7,85);
HTree::insert(0x71,7,86);
HTree::insert(0x72,7,87);
HTree::insert(0xfc,8,88);
HTree::insert(0x73,7,89);
HTree::insert(0xfd,8,90);
HTree::insert(0x1ffb,13,91);
HTree::insert(0x7fff0,19,92);
HTree::insert(0x1ffc,13,93);
HTree::insert(0x3ffc,14,94);
HTree::insert(0x22,6,95);
HTree::insert(0x7ffd,15,96);
HTree::insert(0x3,5,97);
HTree::insert(0x23,6,98);
HTree::insert(0x4,5,99);
HTree::insert(0x24,6,100);
HTree::insert(0x5,5,101);
HTree::insert(0x25,6,102);
HTree::insert(0x26,6,103);
HTree::insert(0x27,6,104);
HTree::insert(0x6,5,105);
HTree::insert(0x74,7,106);
HTree::insert(0x75,7,107);
HTree::insert(0x28,6,108);
HTree::insert(0x29,6,109);
HTree::insert(0x2a,6,110);
HTree::insert(0x7,5,111);
HTree::insert(0x2b,6,112);
HTree::insert(0x76,7,113);
HTree::insert(0x2c,6,114);
HTree::insert(0x8,5,115);
HTree::insert(0x9,5,116);
HTree::insert(0x2d,6,117);
HTree::insert(0x77,7,118);
HTree::insert(0x78,7,119);
HTree::insert(0x79,7,120);
HTree::insert(0x7a,7,121);
HTree::insert(0x7b,7,122);
HTree::insert(0x7ffe,15,123);
HTree::insert(0x7fc,11,124);
HTree::insert(0x3ffd,14,125);
HTree::insert(0x1ffd,13,126);
HTree::insert(0xffffffc,28,127);
HTree::insert(0xfffe6,20,128);
HTree::insert(0x3fffd2,22,129);
HTree::insert(0xfffe7,20,130);
HTree::insert(0xfffe8,20,131);
HTree::insert(0x3fffd3,22,132);
HTree::insert(0x3fffd4,22,133);
HTree::insert(0x3fffd5,22,134);
HTree::insert(0x7fffd9,23,135);
HTree::insert(0x3fffd6,22,136);
HTree::insert(0x7fffda,23,137);
HTree::insert(0x7fffdb,23,138);
HTree::insert(0x7fffdc,23,139);
HTree::insert(0x7fffdd,23,140);
HTree::insert(0x7fffde,23,141);
HTree::insert(0xffffeb,24,142);
HTree::insert(0x7fffdf,23,143);
HTree::insert(0xffffec,24,144);
HTree::insert(0xffffed,24,145);
HTree::insert(0x3fffd7,22,146);
HTree::insert(0x7fffe0,23,147);
HTree::insert(0xffffee,24,148);
HTree::insert(0x7fffe1,23,149);
HTree::insert(0x7fffe2,23,150);
HTree::insert(0x7fffe3,23,151);
HTree::insert(0x7fffe4,23,152);
HTree::insert(0x1fffdc,21,153);
HTree::insert(0x3fffd8,22,154);
HTree::insert(0x7fffe5,23,155);
HTree::insert(0x3fffd9,22,156);
HTree::insert(0x7fffe6,23,157);
HTree::insert(0x7fffe7,23,158);
HTree::insert(0xffffef,24,159);
HTree::insert(0x3fffda,22,160);
HTree::insert(0x1fffdd,21,161);
HTree::insert(0xfffe9,20,162);
HTree::insert(0x3fffdb,22,163);
HTree::insert(0x3fffdc,22,164);
HTree::insert(0x7fffe8,23,165);
HTree::insert(0x7fffe9,23,166);
HTree::insert(0x1fffde,21,167);
HTree::insert(0x7fffea,23,168);
HTree::insert(0x3fffdd,22,169);
HTree::insert(0x3fffde,22,170);
HTree::insert(0xfffff0,24,171);
HTree::insert(0x1fffdf,21,172);
HTree::insert(0x3fffdf,22,173);
HTree::insert(0x7fffeb,23,174);
HTree::insert(0x7fffec,23,175);
HTree::insert(0x1fffe0,21,176);
HTree::insert(0x1fffe1,21,177);
HTree::insert(0x3fffe0,22,178);
HTree::insert(0x1fffe2,21,179);
HTree::insert(0x7fffed,23,180);
HTree::insert(0x3fffe1,22,181);
HTree::insert(0x7fffee,23,182);
HTree::insert(0x7fffef,23,183);
HTree::insert(0xfffea,20,184);
HTree::insert(0x3fffe2,22,185);
HTree::insert(0x3fffe3,22,186);
HTree::insert(0x3fffe4,22,187);
HTree::insert(0x7ffff0,23,188);
HTree::insert(0x3fffe5,22,189);
HTree::insert(0x3fffe6,22,190);
HTree::insert(0x7ffff1,23,191);
HTree::insert(0x3ffffe0,26,192);
HTree::insert(0x3ffffe1,26,193);
HTree::insert(0xfffeb,20,194);
HTree::insert(0x7fff1,19,195);
HTree::insert(0x3fffe7,22,196);
HTree::insert(0x7ffff2,23,197);
HTree::insert(0x3fffe8,22,198);
HTree::insert(0x1ffffec,25,199);
HTree::insert(0x3ffffe2,26,200);
HTree::insert(0x3ffffe3,26,201);
HTree::insert(0x3ffffe4,26,202);
HTree::insert(0x7ffffde,27,203);
HTree::insert(0x7ffffdf,27,204);
HTree::insert(0x3ffffe5,26,205);
HTree::insert(0xfffff1,24,206);
HTree::insert(0x1ffffed,25,207);
HTree::insert(0x7fff2,19,208);
HTree::insert(0x1fffe3,21,209);
HTree::insert(0x3ffffe6,26,210);
HTree::insert(0x7ffffe0,27,211);
HTree::insert(0x7ffffe1,27,212);
HTree::insert(0x3ffffe7,26,213);
HTree::insert(0x7ffffe2,27,214);
HTree::insert(0xfffff2,24,215);
HTree::insert(0x1fffe4,21,216);
HTree::insert(0x1fffe5,21,217);
HTree::insert(0x3ffffe8,26,218);
HTree::insert(0x3ffffe9,26,219);
HTree::insert(0xffffffd,28,220);
HTree::insert(0x7ffffe3,27,221);
HTree::insert(0x7ffffe4,27,222);
HTree::insert(0x7ffffe5,27,223);
HTree::insert(0xfffec,20,224);
HTree::insert(0xfffff3,24,225);
HTree::insert(0xfffed,20,226);
HTree::insert(0x1fffe6,21,227);
HTree::insert(0x3fffe9,22,228);
HTree::insert(0x1fffe7,21,229);
HTree::insert(0x1fffe8,21,230);
HTree::insert(0x7ffff3,23,231);
HTree::insert(0x3fffea,22,232);
HTree::insert(0x3fffeb,22,233);
HTree::insert(0x1ffffee,25,234);
HTree::insert(0x1ffffef,25,235);
HTree::insert(0xfffff4,24,236);
HTree::insert(0xfffff5,24,237);
HTree::insert(0x3ffffea,26,238);
HTree::insert(0x7ffff4,23,239);
HTree::insert(0x3ffffeb,26,240);
HTree::insert(0x7ffffe6,27,241);
HTree::insert(0x3ffffec,26,242);
HTree::insert(0x3ffffed,26,243);
HTree::insert(0x7ffffe7,27,244);
HTree::insert(0x7ffffe8,27,245);
HTree::insert(0x7ffffe9,27,246);
HTree::insert(0x7ffffea,27,247);
HTree::insert(0x7ffffeb,27,248);
HTree::insert(0xffffffe,28,249);
HTree::insert(0x7ffffec,27,250);
HTree::insert(0x7ffffed,27,251);
HTree::insert(0x7ffffee,27,252);
HTree::insert(0x7ffffef,27,253);
HTree::insert(0x7fffff0,27,254);
HTree::insert(0x3ffffee,26,255);
HTree::insert(0x3fffffff,30,256);
