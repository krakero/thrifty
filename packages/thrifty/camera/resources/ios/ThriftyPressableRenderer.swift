import SwiftUI
import UIKit

/// `<native:thrifty-pressable>` — an accessible, scroll-friendly pressable.
///
/// Lays out its children exactly like the core pressable (via
/// `NativeUIColumnRenderer`), but inside a real SwiftUI `Button`:
///
/// - Taps fire `on_press` (a method or `@navigate`) from the Button action.
///   Unlike the core pressable, the element never sets the node-level press
///   fields or the `press-*` props, so NodeView attaches neither its tap
///   gesture nor the `DragGesture(minimumDistance: 0)` press feedback that
///   steals ScrollView panning. Buttons inside a ScrollView let the pan win.
/// - Press feedback (`feedback_scale` / `feedback_opacity` /
///   `feedback_translate_y`) comes from the ButtonStyle's `isPressed`.
/// - `on_long_press` is a simultaneous LongPressGesture; the tap that ends
///   a long press is swallowed, matching the core pressable where tap and
///   long press are exclusive.
/// - VoiceOver: one button element. With `a11y_label` the children are
///   hidden behind that label; without one their text is combined. The
///   default action fires the press; a long press is a named action.
/// - `has_menu` (`:menu`) wraps the content in a SwiftUI Menu, like the core
///   pressable.
struct ThriftyPressableRenderer: View {
    let node: NativeUINode

    var body: some View {
        let props = node.props
        let feedback = ThriftyPressFeedbackStyle(
            scale: CGFloat(props.getFloat("feedback_scale", default: 0)),
            opacity: Double(props.getFloat("feedback_opacity", default: 0)),
            translateY: CGFloat(props.getFloat("feedback_translate_y", default: 0))
        )
        let a11yLabel = props.getString("a11y_label")
        let a11yHint = props.getString("a11y_hint")

        if props.getBool("has_menu") {
            let items = node.children.filter { $0.type == "top_bar_action" }

            // A Menu is exposed as a pop-up button that ignores modifiers
            // wrapping it in a new element, so the label goes on the label
            // content (which the pop-up button reads) and on the Menu itself.
            Menu {
                ForEach(items) { item in
                    ThriftyPressableMenuItem(item: item)
                }
            } label: {
                NativeUIColumnRenderer(node: node)
                    .contentShape(Rectangle())
                    .modifier(ThriftyMenuLabelAccessibility(label: a11yLabel))
            }
            .buttonStyle(feedback)
            .modifier(ThriftyMenuAccessibility(label: a11yLabel, hint: a11yHint))
        } else {
            ThriftyPressableButton(
                node: node,
                tapCallback: props.getCallbackId("on_press"),
                holdCallback: props.getCallbackId("on_long_press"),
                feedback: feedback,
                a11yLabel: a11yLabel,
                a11yHint: a11yHint
            )
        }
    }
}

private struct ThriftyPressableButton: View {
    let node: NativeUINode
    let tapCallback: Int
    let holdCallback: Int
    let feedback: ThriftyPressFeedbackStyle
    let a11yLabel: String
    let a11yHint: String

    /// Set when a long press fires, so the Button action on release is ignored.
    @State private var swallowNextTap = false

    var body: some View {
        Button {
            if swallowNextTap {
                swallowNextTap = false
                return
            }
            tap()
        } label: {
            NativeUIColumnRenderer(node: node)
                .contentShape(Rectangle())
        }
        .buttonStyle(feedback)
        .modifier(ThriftyHoldGesture(enabled: holdCallback != 0) {
            swallowNextTap = true
            hold()
        })
        .modifier(ThriftyPressableAccessibility(
            label: a11yLabel,
            hint: a11yHint,
            onActivate: tapCallback != 0 ? { tap() } : nil,
            onHold: holdCallback != 0 ? { hold() } : nil
        ))
    }

    private func tap() {
        if tapCallback != 0 {
            NativeElementBridge.sendPressEvent(tapCallback, nodeId: node.id)
        }
    }

    private func hold() {
        if holdCallback != 0 {
            UIImpactFeedbackGenerator(style: .medium).impactOccurred()
            NativeElementBridge.sendLongPressEvent(holdCallback, nodeId: node.id)
        }
    }
}

/// Press feedback from the Button's own pressed state (no extra gesture).
/// Zero means "not configured", like the core `press-*` props.
struct ThriftyPressFeedbackStyle: ButtonStyle {
    let scale: CGFloat
    let opacity: Double
    let translateY: CGFloat

    func makeBody(configuration: Configuration) -> some View {
        let pressed = configuration.isPressed

        configuration.label
            .scaleEffect(pressed && scale > 0 ? scale : 1)
            .opacity(pressed && opacity > 0 ? opacity : 1)
            .offset(y: pressed ? translateY : 0)
            .animation(.spring(response: 0.22, dampingFraction: 0.7), value: pressed)
    }
}

/// Long press only when `@longPress` is set. LongPressGesture fails once
/// the finger moves past its small maximum distance, so a pan that starts
/// here still scrolls.
private struct ThriftyHoldGesture: ViewModifier {
    let enabled: Bool
    let action: () -> Void

    func body(content: Content) -> some View {
        if enabled {
            content.simultaneousGesture(
                LongPressGesture(minimumDuration: 0.5).onEnded { _ in action() }
            )
        } else {
            content
        }
    }
}

private struct ThriftyPressableAccessibility: ViewModifier {
    let label: String
    let hint: String
    let onActivate: (() -> Void)?
    let onHold: (() -> Void)?

    func body(content: Content) -> some View {
        labelled(content)
            .accessibilityAddTraits(.isButton)
            .accessibilityHint(hint.isEmpty ? Text("") : Text(hint))
            .modifier(ThriftyOptionalAction(action: onActivate))
            .modifier(ThriftyOptionalNamedAction(name: "Long press", action: onHold))
    }

    @ViewBuilder
    private func labelled(_ content: Content) -> some View {
        if label.isEmpty {
            content.accessibilityElement(children: .combine)
        } else {
            content
                .accessibilityElement(children: .ignore)
                .accessibilityLabel(Text(label))
        }
    }
}

/// The Menu's label content: one element carrying the label (or the
/// children's text combined when there is none).
private struct ThriftyMenuLabelAccessibility: ViewModifier {
    let label: String

    func body(content: Content) -> some View {
        if label.isEmpty {
            content.accessibilityElement(children: .combine)
        } else {
            content
                .accessibilityElement(children: .ignore)
                .accessibilityLabel(Text(label))
        }
    }
}

/// Label and hint on the Menu's own pop-up button element, without
/// replacing it (so it keeps its pop-up trait and opens the menu).
private struct ThriftyMenuAccessibility: ViewModifier {
    let label: String
    let hint: String

    func body(content: Content) -> some View {
        content
            .modifier(ThriftyOptionalLabel(label: label))
            .accessibilityHint(hint.isEmpty ? Text("") : Text(hint))
    }
}

private struct ThriftyOptionalLabel: ViewModifier {
    let label: String

    func body(content: Content) -> some View {
        if label.isEmpty {
            content
        } else {
            content.accessibilityLabel(Text(label))
        }
    }
}

private struct ThriftyOptionalAction: ViewModifier {
    let action: (() -> Void)?

    func body(content: Content) -> some View {
        if let action = action {
            content.accessibilityAction { action() }
        } else {
            content
        }
    }
}

private struct ThriftyOptionalNamedAction: ViewModifier {
    let name: String
    let action: (() -> Void)?

    func body(content: Content) -> some View {
        if let action = action {
            content.accessibilityAction(named: Text(name)) { action() }
        } else {
            content
        }
    }
}

/// One `:menu` row, mirroring the core pressable's menu (a Button or a Divider).
private struct ThriftyPressableMenuItem: View {
    let item: NativeUINode

    var body: some View {
        if item.props.getBool("divider") {
            Divider()
        } else {
            let label = item.props.getString("label", default: "")
            let icon = item.props.getString("icon", default: "")
            let isDestructive = item.props.getBool("destructive")

            Button(role: isDestructive ? .destructive : nil) {
                if item.onPress != 0 {
                    NativeElementBridge.sendPressEvent(item.onPress, nodeId: item.id)
                }
            } label: {
                if !icon.isEmpty {
                    Label(label, systemImage: getIconForName(icon))
                } else {
                    Text(label)
                }
            }
            .tint(isDestructive ? .red : nil)
        }
    }
}
