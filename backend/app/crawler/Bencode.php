<?php

namespace app\crawler;

use RuntimeException;

class Bencode
{
    public static function decode(string $data)
    {
        $i = 0;
        $out = self::decodeAt($data, $i);
        return $out;
    }

    public static function decodeWithConsumed(string $data): array
    {
        $i = 0;
        $out = self::decodeAt($data, $i);
        return [$out, $i];
    }

    private static function decodeAt(string $data, int &$i)
    {
        if ($i >= strlen($data)) {
            throw new RuntimeException('bencode eof');
        }
        $c = $data[$i];
        if ($c === 'i') {
            $i++;
            $end = strpos($data, 'e', $i);
            if ($end === false) {
                throw new RuntimeException('bencode int');
            }
            $num = substr($data, $i, $end - $i);
            $i = $end + 1;
            return (int) $num;
        }
        if ($c === 'l') {
            $i++;
            $list = [];
            while ($data[$i] !== 'e') {
                $list[] = self::decodeAt($data, $i);
                if ($i >= strlen($data)) {
                    throw new RuntimeException('bencode list');
                }
            }
            $i++;
            return $list;
        }
        if ($c === 'd') {
            $i++;
            $dict = [];
            while ($data[$i] !== 'e') {
                $key = self::decodeAt($data, $i);
                if (!is_string($key)) {
                    throw new RuntimeException('bencode dict key');
                }
                $dict[$key] = self::decodeAt($data, $i);
                if ($i >= strlen($data)) {
                    throw new RuntimeException('bencode dict');
                }
            }
            $i++;
            return $dict;
        }
        if ($c >= '0' && $c <= '9') {
            $colon = strpos($data, ':', $i);
            if ($colon === false) {
                throw new RuntimeException('bencode str');
            }
            $len = (int) substr($data, $i, $colon - $i);
            $i = $colon + 1;
            $str = substr($data, $i, $len);
            $i += $len;
            return $str;
        }

        throw new RuntimeException('bencode token');
    }

    public static function encode($value): string
    {
        if (is_int($value)) {
            return 'i' . $value . 'e';
        }
        if (is_string($value)) {
            return strlen($value) . ':' . $value;
        }
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                $out = 'l';
                foreach ($value as $v) {
                    $out .= self::encode($v);
                }
                return $out . 'e';
            }
            ksort($value, SORT_STRING);
            $out = 'd';
            foreach ($value as $k => $v) {
                $out .= self::encode((string) $k);
                $out .= self::encode($v);
            }
            return $out . 'e';
        }
        if ($value === null) {
            return '0:';
        }
        throw new RuntimeException('bencode type');
    }
}
