<native:column class="w-full h-full">
    <native:thrifty-camera ref="camera" :scanning="$scanning" :interval="$interval" facing="{{ $facing }}" frames-directory="{{ $framesDirectory }}" class="w-full h-full" />
</native:column>
