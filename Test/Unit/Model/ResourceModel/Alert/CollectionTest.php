<?php
/**
 * CollectionTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\ResourceModel\Alert;

use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Model\Alert;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Commerce\ImportMonitor\Model\ResourceModel\Alert\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The real constructor builds a SELECT through the object manager, which a unit
 * test does not have.
 */
class CollectionTest extends TestCase
{
    public function testTheCollectionIsWiredToTheEntityAndItsResource(): void
    {
        $collection = $this->collection();

        $this->assertSame(Alert::class, $collection->getModelName());
        $this->assertSame(AlertResource::class, $collection->getResourceModelName());
    }

    /**
     * Set through the setter: the parent declares `$_idFieldName` untyped.
     */
    public function testTheIdFieldIsSetThroughTheSetter(): void
    {
        $this->assertSame(AlertInterface::ALERT_ID, $this->collection()->getIdFieldName());
    }

    /**
     * The framework default is `id`, which is not this table's key - left
     * unset, the alert grid's row actions look up the wrong column.
     */
    public function testTheIdFieldIsNotTheFrameworkDefault(): void
    {
        $this->assertNotSame('id', $this->collection()->getIdFieldName());
    }

    private function collection(): Collection
    {
        $collection = (new ReflectionClass(Collection::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($collection, '_construct'))->invoke($collection);

        return $collection;
    }
}
