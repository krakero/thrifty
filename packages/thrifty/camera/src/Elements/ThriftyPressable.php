<?php

namespace Thrifty\Camera\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\NativeElementCollector;

/**
 * `<native:thrifty-pressable>` — an accessible, scroll-friendly drop-in for
 * `<native:pressable>`, rendered on iOS as a real SwiftUI Button.
 *
 * It takes the same attributes (`@press`, `@longPress`, `@navigate`,
 * `press-scale` / `press-opacity` / `press-translate-y`, `:menu`, class, ref)
 * plus `a11y-label` / `a11y-hint`, which the core pressable renderer drops.
 *
 * The press callbacks travel as `on_press` / `on_long_press` in the props
 * bag (like `<native:button>`, so the test harness's tap()/longPress() still
 * find them) rather than in the node-level press fields, and the press
 * feedback travels as `feedback_*` props rather than `press-*`: the core NodeView attaches its tap gesture and a
 * `DragGesture(minimumDistance: 0)` press-feedback modifier to any node that
 * has those, and that DragGesture steals ScrollView panning. The renderer's
 * Button fires the callbacks and applies the feedback via a ButtonStyle.
 */
class ThriftyPressable extends Pressable
{
    protected string $type = 'thrifty_pressable';

    /** Press-feedback attribute → this element's prop name. */
    protected const FeedbackProps = [
        'press-scale' => 'feedback_scale',
        'press-opacity' => 'feedback_opacity',
        'press-translate-y' => 'feedback_translate_y',
    ];

    protected ?string $tapMethod = null;

    protected ?string $holdMethod = null;

    /** @var array<string, mixed>|null */
    protected ?array $tapNavigation = null;

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function applyAttributes(array $attrs): void
    {
        parent::applyAttributes($attrs);

        // Core elements get these from the collector's built-in path; plugin
        // elements must forward them (setProp renames press feedback).
        foreach (NativeElementCollector::buildAnimationProps($attrs) as $key => $value) {
            $this->setProp($key, $value);
        }

        $this->applyA11yAttributes($attrs);
    }

    /**
     * Press feedback set by anyone (this class or the collector) is stored
     * under `feedback_*`, so the core press-feedback gesture never attaches.
     */
    public function setProp(string $key, mixed $value): static
    {
        return parent::setProp(self::FeedbackProps[$key] ?? $key, $value);
    }

    public function pressScale(float $scale): static
    {
        return $this->setProp('feedback_scale', $scale);
    }

    public function pressOpacity(float $opacity): static
    {
        return $this->setProp('feedback_opacity', $opacity);
    }

    /**
     * Kept off the node-level press field (see the class docblock).
     */
    public function onPress(string $method): static
    {
        $this->tapMethod = $method;

        return $this;
    }

    /**
     * Kept off the node-level long-press field (see the class docblock).
     */
    public function onLongPress(string $method): static
    {
        $this->holdMethod = $method;

        return $this;
    }

    /**
     * `@navigate` fires from the Button like `@press` does.
     *
     * @param  array<string, mixed>  $config
     */
    public function setNavigateConfig(array $config): static
    {
        $this->tapNavigation = $config;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveProps(CallbackRegistry $registry): array
    {
        $props = parent::resolveProps($registry);

        if ($this->tapNavigation !== null) {
            $navKey = $registry->registerNavigation($this->tapNavigation);
            $props['on_press'] = $registry->register("__navigate('{$navKey}')");
        } elseif ($this->tapMethod !== null) {
            $props['on_press'] = $registry->register($this->tapMethod);
        }

        if ($this->holdMethod !== null) {
            $props['on_long_press'] = $registry->register($this->holdMethod);
        }

        return $props;
    }
}
