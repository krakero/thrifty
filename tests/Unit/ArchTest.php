<?php

arch()->preset()->php();

arch('native components follow the SuperNative conventions')
    ->expect('App\NativeComponents')
    ->toExtend('Native\Mobile\Edge\NativeComponent')
    ->ignoring('App\NativeComponents\Layouts')
    ->toHaveMethod('render')
    ->ignoring('App\NativeComponents\Layouts');

arch('layouts provide shared native chrome')
    ->expect('App\NativeComponents\Layouts')
    ->toExtend('Native\Mobile\Edge\Layouts\NativeLayout');
