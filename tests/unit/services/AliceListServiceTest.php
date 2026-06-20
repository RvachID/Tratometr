<?php

namespace tests\unit\services;

use app\services\Alice\AliceListService;
use ReflectionMethod;

class AliceListServiceTest extends \Codeception\Test\Unit
{
    /**
     * @dataProvider explicitAddCommandProvider
     */
    public function testExtractsItemsOnlyFromExplicitAddCommands(string $command, array $expected): void
    {
        $this->assertSame($expected, $this->extractItems($command));
    }

    public function explicitAddCommandProvider(): array
    {
        return [
            ['добавь хлеб и молоко', ['хлеб', 'молоко']],
            ['добавить в список покупок сыр', ['сыр']],
            ['добавь добавь яблоки', ['яблоки']],
            ['добавь', []],
        ];
    }

    /**
     * @dataProvider unknownCommandProvider
     */
    public function testDoesNotTreatUnknownCommandsAsShoppingItems(string $command): void
    {
        $this->assertSame([], $this->extractItems($command));
    }

    public function unknownCommandProvider(): array
    {
        return [
            ['как дела'],
            ['помощь'],
            ['что ты умеешь'],
            ['покажи команды'],
            ['молоко в список'],
        ];
    }

    private function extractItems(string $command): array
    {
        $method = new ReflectionMethod(AliceListService::class, 'extractItemsFromAddCommand');
        $method->setAccessible(true);

        return $method->invoke(new AliceListService(), $command);
    }
}
