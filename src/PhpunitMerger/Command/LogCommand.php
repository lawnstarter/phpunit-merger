<?php

declare(strict_types=1);

namespace Nimut\PhpunitMerger\Command;

use DOMElement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class LogCommand extends Command
{
    /**
     * @var \DOMDocument
     */
    private $document;

    /**
     * @var \DOMElement[]
     */
    private $domElements = [];

    private $metaAttributes = ['name', 'file'];

    private $numericAttributes = ['errors', 'warnings', 'failures', 'skipped', 'tests'];

    protected function configure()
    {
        $this->setName('log')
            ->setDescription('Merges multiple PHPUnit JUnit xml files into one')
            ->addArgument(
                'directory',
                InputArgument::REQUIRED,
                'The directory containing PHPUnit JUnit xml files'
            )
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'The file where to write the merged result'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $finder = new Finder();
        $finder->files()
            ->in(realpath($input->getArgument('directory')));

        $this->document = new \DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;

        $root = $this->document->createElement('testsuites');
        $this->document->appendChild($root);

        foreach ($finder as $file) {
            try {
                $xml = new \SimpleXMLElement(file_get_contents($file->getRealPath()));
                $xmlArray = json_decode(json_encode($xml), true);
                if (!empty($xmlArray)) {
                    $this->addTestSuites($root, $xmlArray);
                }
            } catch (\Exception $exception) {
                // Initial fallthrough
            }
        }

        foreach ($this->domElements as $domElement) {
            if ($domElement->hasAttribute('parent')) {
                $domElement->removeAttribute('parent');
            }
        }

        $file = $input->getArgument('file');
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0777, true);
        }
        $this->document->save($input->getArgument('file'));

        return 0;
    }

    private function addTestSuites(\DOMElement $parent, array $testSuites)
    {
        foreach ($testSuites as $testSuite) {
            if (empty($testSuite['@attributes']['name'])) {
                if (!empty($testSuite['testsuite'])) {
                    $children = isset($testSuite['testsuite']['@attributes']) ? [$testSuite['testsuite']] : $testSuite['testsuite'];
                    $this->addTestSuites($parent, $children);
                }
                continue;
            }
            $name = $testSuite['@attributes']['name'];
            $isFirstRun = $parent->nodeName === 'testsuites';

            if (isset($this->domElements[$name])) {
                $element = $this->domElements[$name];
            } else {
                $element = $this->document->createElement('testsuite');
                $element->setAttribute('parent', $parent->getAttribute('name'));
                // For the first testsuite element, only set values for the meta attributes
                $allowedAttributes = $isFirstRun ? $this->metaAttributes : array_merge($this->metaAttributes, $this->numericAttributes);
                $attributes = $testSuite['@attributes'] ?? [];
                foreach ($attributes as $key => $value) {
                    $value = in_array($key, $allowedAttributes) ? $value : 0;
                    $element->setAttribute($key, (string)$value);
                }
                $parent->appendChild($element);
                $this->domElements[$name] = $element;
            }

            if (!empty($testSuite['testsuite'])) {
                $children = isset($testSuite['testsuite']['@attributes']) ? [$testSuite['testsuite']] : $testSuite['testsuite'];
                $this->addTestSuites($element, $children);
            }

            if (!empty($testSuite['testcase'])) {
                $children = isset($testSuite['testcase']['@attributes']) ? [$testSuite['testcase']] : $testSuite['testcase'];
                $this->addTestCases($element, $children);
            }
        }
    }

    private function addTestCases(\DOMElement $parent, array $testCases)
    {
        foreach ($testCases as $testCase) {
            $attributes = $testCase['@attributes'] ?? [];
            if (empty($testCase['@attributes']['name'])) {
                continue;
            }
            $name = $testCase['@attributes']['name'];

            if (isset($this->domElements[$name])) {
                continue;
            }

            $element = $this->document->createElement('testcase');
            foreach ($attributes as $key => $value) {
                $element->setAttribute($key, (string)$value);
                if (!is_numeric($value)) {
                    continue;
                }
                $this->addAttributeValueToTestSuite($parent, $key, $value);
            }
            $this->addTestCaseChild($element, $testCase);

            $parent->appendChild($element);
            $this->domElements[$name] = $element;
        }
    }

    private function addTestCaseChild(DOMElement &$element, array $testCase): void
    {
        $childTypes = ['skipped', 'failure', 'error'];
        foreach ($childTypes as $type) {
            if (isset($testCase[$type])) {
                if (!is_array($testCase[$type])) {
                    $testCase[$type] = [$testCase[$type]];
                }

                foreach ($testCase[$type] as $child) {
                    $childElement = $this->document->createElement($type);
                    $childElement->appendChild($this->document->createTextNode($child));
                    $element->appendChild($childElement);
                }
            }
        }
    }

    private function addAttributeValueToTestSuite(\DOMElement $element, $key, $value)
    {
        $currentValue = $element->hasAttribute($key) ? $element->getAttribute($key) : 0;
        $element->setAttribute($key, (string)($currentValue + $value));

        if ($element->hasAttribute('parent')) {
            $parent = $element->getAttribute('parent');
            if (isset($this->domElements[$parent])) {
                $this->addAttributeValueToTestSuite($this->domElements[$parent], $key, $value);
            }
        }
    }
}
