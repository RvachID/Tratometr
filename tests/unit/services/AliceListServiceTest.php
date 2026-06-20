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

    /**
     * @dataProvider clearCommandProvider
     */
    public function testRecognizesOnlyWholeListClearCommands(string $command, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke('isClearCommand', $command));
    }

    public function clearCommandProvider(): array
    {
        return [
            ['очисти список', true],
            ['сбрось весь список покупок', true],
            ['удали всё из списка', true],
            ['удали молоко из списка', false],
            ['убери хлеб из списка', false],
        ];
    }

    /**
     * @dataProvider deleteQueryProvider
     */
    public function testExtractsAProductFromDeleteCommand(string $command, ?string $expected): void
    {
        $this->assertSame($expected, $this->invoke('extractDeleteQuery', $command));
    }

    public function deleteQueryProvider(): array
    {
        return [
            ['удали молоко', 'молоко'],
            ['убери молоко 33 коровы из списка', 'молоко 33 коровы'],
            ['очисти список', null],
            ['удали всё из списка', null],
        ];
    }

    public function testFindsExactPartialAndFuzzyDeleteMatches(): void
    {
        $items = [
            $this->item('Молоко 33 коровы'),
            $this->item('Молоко безлактозное'),
            $this->item('Хлеб'),
        ];

        $exact = $this->invoke('findDeleteMatches', 'молоко 33 коровы', $items);
        $partial = $this->invoke('findDeleteMatches', 'молоко', $items);
        $fuzzy = $this->invoke('findDeleteMatches', 'малако', $items);

        $this->assertCount(1, $exact['exact']);
        $this->assertCount(2, $partial['partial']);
        $this->assertCount(2, $fuzzy['fuzzy']);
    }

    /**
     * @dataProvider helpCommandProvider
     */
    public function testRecognizesHelpCommands(string $command, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke('isHelpCommand', $command));
    }

    public function helpCommandProvider(): array
    {
        return [
            ['помощь', true],
            ['что ты умеешь', true],
            ['какие есть команды', true],
            ['расскажи о командах', true],
            ['как тобой пользоваться', true],
            ['добавь помощь', false],
        ];
    }

    public function testHelpTextDescribesCurrentCommands(): void
    {
        $text = (new AliceListService())->getHelpText();

        $this->assertStringContainsString('добавь хлеб и молоко', $text);
        $this->assertStringContainsString('что в списке', $text);
        $this->assertStringContainsString('удали молоко', $text);
        $this->assertStringContainsString('очисти список', $text);
    }

    private function extractItems(string $command): array
    {
        return $this->invoke('extractItemsFromAddCommand', $command);
    }

    private function invoke(string $methodName, ...$arguments)
    {
        $method = new ReflectionMethod(AliceListService::class, $methodName);
        $method->setAccessible(true);

        return $method->invoke(new AliceListService(), ...$arguments);
    }

    private function item(string $title): object
    {
        return (object)['title' => $title];
    }
}
