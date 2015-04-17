<?php

namespace Pagespeed;

/*
$ps = new Pagespeed();
$ps->setApiKey('asdfasdfasdfasdfasdf');
$ps->setUrl('someurl.com');
$ps->setStrategy('mobile');
$ps->runInsights();
$ps->getSpeedIssues();
$ps->getUsabilityIssues();
*/

class Pagespeed
{
    private $json = null;
    private $baseUrl = "https://www.googleapis.com/pagespeedonline/v2/runPagespeed";
    private $pageUrl = null;
    private $strategy = "mobile";
    private $googleApiKey = "";

    public static function getInstance($key=null, $url=null)
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new static($key, $url);
        }

        return $instance;
    }

    protected function __construct($key = null, $url = null)
    {
        $this->setApiKey($key);
        $this->setUrl($url);
    }

    public function setApiKey($key = null)
    {
        $this->googleApiKey = $key;
    }

    public function setUrl($url = null)
    {
        $this->pageUrl = $url;
    }

    public function setStrategy($strategy = null)
    {
        $strategy = strtolower($strategy);
        $this->strategy = in_array($strategy, ['mobile', 'desktop']) ? $strategy : 'mobile';
    }

    public function runInsights()
    {
        if (!$this->googleApiKey || !$this->pageUrl) {
            return false;
        }
        $this->parseJson();
        return $this->json;
    }

    private function parseJson()
    {
        $this->json = json_decode($this->getResponse(), true);
        if (isset($this->json['error']['code']) && $this->json['error']['code'] != '200') {
            //Log::addEntry($this->generateRequestUrl."<br><br>".print_r($this->json,true),t('PageSpeed'));
            $this->json = false;
        }
    }

    private function getResponse()
    {
        $ch = curl_init($this->generateRequestUrl());

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_NOBODY => 0,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    private function generateRequestUrl()
    {
        $query = [
            'url' => $this->pageUrl
            , 'strategy' => $this->strategy
            , 'key' => $this->googleApiKey
        ];
        foreach ($query as $key => $value) {
            $query[$key] = $key . '=' . $value;
        }
        return $this->baseUrl . "?" . implode('&', $query);
    }

    private function filesize($bytes, $precision = 2)
    {
        $size = $bytes / 1024;
        if ($size < 1024) {
            $size = number_format($size, $precision) . "KB";
        } elseif (($size / 1024) < 1024) {
            $size = number_format($size / 1024, $precision) . "MB";
        } else {
            $size = number_format($size / 1024 / 1024, $precision) . "GB";
        }

        return $size;
    }

    public function getResourceWeight()
    {
        $stats = false;
        if (isset($this->json['pageStats'])) {
            $stats = [
                'Number of Hosts' => $this->json['pageStats']['numberHosts'],
                'Number of Resources' => $this->json['pageStats']['numberResources'],
                'Number of Static Resources' => $this->json['pageStats']['numberStaticResources'],
                'Number of CSS Resources' => $this->json['pageStats']['numberCssResources'],
                'Number of JS Resources' => $this->json['pageStats']['numberJsResources'],
                'Request Size' => $this->filesize($this->json['pageStats']['totalRequestBytes']),
                'Html Response Size' => $this->filesize($this->json['pageStats']['htmlResponseBytes']),
                'CSS Response Size' => $this->filesize($this->json['pageStats']['cssResponseBytes']),
                'Image Response Size' => $this->filesize($this->json['pageStats']['imageResponseBytes']),
                'JS Response Size' => $this->filesize($this->json['pageStats']['javascriptResponseBytes']),
                'Other Response Size' => $this->filesize($this->json['pageStats']['otherResponseBytes']),
            ];
        }
        return $stats;
    }

    public function getSpeedIssues($handle = null)
    {
        return $this->getIssues('SPEED', $handle);
    }

    public function getUsabilityIssues($handle = null)
    {
        return $this->getIssues('USABILITY', $handle);
    }

    private function getIssues($group = 'SPEED', $handle = null)
    {
        if (!$this->json) {
            return false;
        }
        $issues = false;
        if (isset($this->json['formattedResults']['ruleResults'])) {
            $ruleResults = $this->getRuleResults($group, $handle);
            if (count($ruleResults) > 0) {
                $issues = $ruleResults;
            }
        }
        return $issues;

    }

    private function getRuleResults($group = 'SPEED', $handle = null)
    {
        if (!$this->json) {
            return false;
        }
        $rules = [];
        foreach ($this->json['formattedResults']['ruleResults'] as $ruleHandle => $rule) {
            if (in_array($group, $rule['groups']) && (!is_null($handle) && $handle == $ruleHandle)) {
                $rules[$ruleHandle] = $this->getRule($rule);
            }
        }
        return $rules;
    }

    private function getRule($rule)
    {
        $result = [
            'name' => null,
            'summary' => null,
            'impact' => null,
            'urlBlocks' => null
        ];
        if (isset($rule['localizedRuleName'])) {
            $result['name'] = $rule['localizedRuleName'];
        }
        if (isset($rule['summary'])) {
            $result['summary'] = $this->formatValue($rule['summary']);
        }
        if (isset($rule['ruleImpact'])) {
            $result['impact'] = $rule['ruleImpact'] ? $rule['ruleImpact'] : false;
        }
        if (isset($rule['urlBlocks'])) {
            foreach ($rule['urlBlocks'] as $ruleUrlBlock) {
                $urlBlock = [
                    'header' => null,
                    'urls' => null
                ];
                if (isset($ruleUrlBlock['header'])) {
                    $urlBlock['header'] = $this->formatValue($ruleUrlBlock['header']);
                }
                if (isset($ruleUrlBlock['urls'])) {
                    foreach ($ruleUrlBlock['urls'] as $url) {
                        $urlBlock['urls'][] = $this->formatValue($url['result']);
                    }
                } else {
                    unset($urlBlock['urls']);
                }
                $result['urlBlocks'][] = $urlBlock;
            }

        } else {
            unset($result['urlBlocks']);
        }
        if (count($result['urlBlocks']) == 0) {
            unset($result['urlBlocks']);
        }
        return $result;
    }

    private function formatValue($value)
    {
        $formattedValue = $value['format'];
        if (isset($value['args'])) {
            foreach ($value['args'] as $arg) {
                $key = $arg['key'];
                $value = $arg['value'];
                if ($arg['type'] == 'HYPERLINK') {
                    $formattedValue = str_replace('{{BEGIN_' . $key . '}}', '<a href="' . $value . '" target="_blank">', $formattedValue);
                    $formattedValue = str_replace('{{END_' . $key . '}}', '</a>', $formattedValue);
                } else {
                    $formattedValue = str_replace('{{' . $key . '}}', $value, $formattedValue);
                }
            }
        }
        return $formattedValue;
    }

}
