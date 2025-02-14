<?php
namespace PoP\ComponentModel\DataStructure;

interface DataStructureFormatterInterface
{
    public static function getName();
    public function getFormattedData($data);
    public function getContentType();
    public function outputResponse(&$data, array $headers = []);
}
