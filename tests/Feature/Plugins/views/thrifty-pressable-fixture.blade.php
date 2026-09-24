@use('Native\Mobile\Edge\Layouts\Builders\NavAction')
<native:scroll-view class="w-full h-full">
    <native:column class="gap-2">
        <native:pressable ref="core" @press="save('draft')" @longPress="hold" press-scale="0.97" press-opacity="0.8" class="p-4 rounded-xl bg-theme-surface flex-row gap-2">
            <native:text>Core</native:text>
        </native:pressable>

        <native:thrifty-pressable ref="thrifty" @press="save('draft')" @longPress="hold" press-scale="0.97" press-opacity="0.8" class="p-4 rounded-xl bg-theme-surface flex-row gap-2" a11y-label="Save draft" a11y-hint="Saves the draft">
            <native:text>Thrifty</native:text>
        </native:thrifty-pressable>

        <native:thrifty-pressable ref="find" @navigate="'/finds/42?from=history', ['from' => 'history']">
            <native:text>Open find</native:text>
        </native:thrifty-pressable>

        <native:thrifty-pressable ref="menu" :menu="[NavAction::make('one')->label('One')->press('save')]" a11y-label="More">
            <native:text>Menu</native:text>
        </native:thrifty-pressable>
    </native:column>
</native:scroll-view>
