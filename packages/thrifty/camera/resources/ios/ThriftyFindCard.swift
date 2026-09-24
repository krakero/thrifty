import Foundation
import UIKit

/// The data behind a shared find card (see ThriftyCamera::shareFindCard()).
struct ThriftyFindCard {
    struct Row {
        let label: String
        let value: String
    }

    let title: String
    let subtitle: String
    let imagePath: String
    /// Normalized 0–1000 bounding box within the image.
    let box: CGRect?
    let rows: [Row]
    let summary: String

    init(parameters: [String: Any]) {
        title = parameters["title"] as? String ?? ""
        subtitle = parameters["subtitle"] as? String ?? ""
        imagePath = parameters["imagePath"] as? String ?? ""
        summary = parameters["summary"] as? String ?? ""

        rows = (parameters["rows"] as? [[String: Any]] ?? []).map { row in
            Row(label: row["label"] as? String ?? "", value: row["value"] as? String ?? "")
        }

        if let box = parameters["box"] as? [String: Any],
           let xMin = Self.number(box["xMin"]),
           let yMin = Self.number(box["yMin"]),
           let xMax = Self.number(box["xMax"]),
           let yMax = Self.number(box["yMax"]),
           xMax > xMin, yMax > yMin {
            self.box = CGRect(x: xMin, y: yMin, width: xMax - xMin, height: yMax - yMin)
        } else {
            box = nil
        }
    }

    private static func number(_ value: Any?) -> CGFloat? {
        if let number = value as? NSNumber {
            return CGFloat(truncating: number)
        }

        if let string = value as? String, let double = Double(string) {
            return CGFloat(double)
        }

        return nil
    }
}

/// Draws the branded share image: the frame with the find boxed in tomato,
/// then the title, subtitle, mono label/value rows and the summary on the
/// dark price-tag background.
enum ThriftyFindCardRenderer {
    private static let width: CGFloat = 1080
    private static let padding: CGFloat = 64
    private static let background = UIColor(red: 0x14 / 255, green: 0x12 / 255, blue: 0x10 / 255, alpha: 1)
    private static let text = UIColor(red: 0xF5 / 255, green: 0xEF / 255, blue: 0xE6 / 255, alpha: 1)
    private static let tomato = UIColor(red: 0xFF / 255, green: 0x6B / 255, blue: 0x4A / 255, alpha: 1)
    private static let mint = UIColor(red: 0x5E / 255, green: 0xE6 / 255, blue: 0xA8 / 255, alpha: 1)

    static func render(_ card: ThriftyFindCard) -> UIImage {
        let contentWidth = width - padding * 2
        let image = UIImage(contentsOfFile: card.imagePath)

        let imageRect: CGRect? = image.map { image in
            let aspect = image.size.height / max(image.size.width, 1)
            let height = min(contentWidth * aspect, contentWidth * 1.25)
            let fittedWidth = min(contentWidth, height / max(aspect, 0.01))
            return CGRect(x: (width - fittedWidth) / 2, y: padding, width: fittedWidth, height: height)
        }

        let brand = attributed("THRIFTY FIND", font: font("DMMono-Medium", size: 26, fallback: .monospacedSystemFont(ofSize: 26, weight: .medium)), color: tomato, kern: 4)
        let title = attributed(card.title, font: font("BricolageGrotesque-ExtraBold", size: 64, fallback: .systemFont(ofSize: 64, weight: .heavy)), color: text)
        let subtitle = attributed(card.subtitle, font: font("BricolageGrotesque-Regular", size: 34, fallback: .systemFont(ofSize: 34)), color: text.withAlphaComponent(0.7))
        let summary = attributed(card.summary, font: font("BricolageGrotesque-Regular", size: 32, fallback: .systemFont(ofSize: 32)), color: text.withAlphaComponent(0.9), lineSpacing: 8)
        let footer = attributed("Found with Thrifty", font: font("DMMono-Medium", size: 24, fallback: .monospacedSystemFont(ofSize: 24, weight: .medium)), color: text.withAlphaComponent(0.5))

        let rowFont = font("DMMono-Medium", size: 30, fallback: .monospacedSystemFont(ofSize: 30, weight: .medium))
        let rowHeight: CGFloat = 64

        // Measure
        var cursor = (imageRect?.maxY ?? 0) + padding
        let brandHeight = height(of: brand, width: contentWidth)
        let titleHeight = height(of: title, width: contentWidth)
        let subtitleHeight = card.subtitle.isEmpty ? 0 : height(of: subtitle, width: contentWidth)
        let summaryHeight = card.summary.isEmpty ? 0 : height(of: summary, width: contentWidth)
        let footerHeight = height(of: footer, width: contentWidth)

        cursor += brandHeight + 16 + titleHeight
        cursor += subtitleHeight > 0 ? 12 + subtitleHeight : 0
        cursor += card.rows.isEmpty ? 0 : 36 + CGFloat(card.rows.count) * rowHeight
        cursor += summaryHeight > 0 ? 36 + summaryHeight : 0
        cursor += 48 + footerHeight + padding

        let size = CGSize(width: width, height: ceil(cursor))
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        format.opaque = true

        return UIGraphicsImageRenderer(size: size, format: format).image { context in
            background.setFill()
            context.fill(CGRect(origin: .zero, size: size))

            if let image = image, let rect = imageRect {
                drawFrame(image, in: rect, box: card.box, context: context.cgContext)
            }

            var y = (imageRect?.maxY ?? 0) + padding

            brand.draw(with: CGRect(x: padding, y: y, width: contentWidth, height: brandHeight), options: .usesLineFragmentOrigin, context: nil)
            y += brandHeight + 16

            title.draw(with: CGRect(x: padding, y: y, width: contentWidth, height: titleHeight), options: .usesLineFragmentOrigin, context: nil)
            y += titleHeight

            if subtitleHeight > 0 {
                y += 12
                subtitle.draw(with: CGRect(x: padding, y: y, width: contentWidth, height: subtitleHeight), options: .usesLineFragmentOrigin, context: nil)
                y += subtitleHeight
            }

            if !card.rows.isEmpty {
                y += 36
                for row in card.rows {
                    drawRow(row, font: rowFont, top: y, height: rowHeight, contentWidth: contentWidth)
                    y += rowHeight
                }
            }

            if summaryHeight > 0 {
                y += 36
                summary.draw(with: CGRect(x: padding, y: y, width: contentWidth, height: summaryHeight), options: .usesLineFragmentOrigin, context: nil)
                y += summaryHeight
            }

            y += 48
            footer.draw(with: CGRect(x: padding, y: y, width: contentWidth, height: footerHeight), options: .usesLineFragmentOrigin, context: nil)
        }
    }

    private static func drawFrame(_ image: UIImage, in rect: CGRect, box: CGRect?, context: CGContext) {
        context.saveGState()
        UIBezierPath(roundedRect: rect, cornerRadius: 32).addClip()
        image.draw(in: rect)
        context.restoreGState()

        guard let box = box else {
            return
        }

        let boxRect = CGRect(
            x: rect.minX + box.minX / 1000 * rect.width,
            y: rect.minY + box.minY / 1000 * rect.height,
            width: box.width / 1000 * rect.width,
            height: box.height / 1000 * rect.height
        ).intersection(rect).insetBy(dx: 4, dy: 4)

        guard !boxRect.isNull, boxRect.width > 8, boxRect.height > 8 else {
            return
        }

        let path = UIBezierPath(roundedRect: boxRect, cornerRadius: 14)
        path.lineWidth = 8
        tomato.setStroke()
        path.stroke()
    }

    private static func drawRow(_ row: ThriftyFindCard.Row, font: UIFont, top: CGFloat, height: CGFloat, contentWidth: CGFloat) {
        let divider = UIBezierPath(rect: CGRect(x: padding, y: top, width: contentWidth, height: 2))
        text.withAlphaComponent(0.12).setFill()
        divider.fill()

        let isResale = row.label.lowercased().contains("resale") || row.label.lowercased().contains("profit")
        let label = attributed(row.label.uppercased(), font: font, color: text.withAlphaComponent(0.6), kern: 1)
        let value = attributed(row.value, font: font, color: isResale ? mint : text, alignment: .right)

        let lineHeight = font.lineHeight
        let textTop = top + (height - lineHeight) / 2
        let half = contentWidth / 2

        label.draw(with: CGRect(x: padding, y: textTop, width: half, height: lineHeight), options: [.usesLineFragmentOrigin, .truncatesLastVisibleLine], context: nil)
        value.draw(with: CGRect(x: padding + half, y: textTop, width: half, height: lineHeight), options: [.usesLineFragmentOrigin, .truncatesLastVisibleLine], context: nil)
    }

    private static func font(_ name: String, size: CGFloat, fallback: UIFont) -> UIFont {
        UIFont(name: name, size: size) ?? fallback
    }

    private static func attributed(
        _ string: String,
        font: UIFont,
        color: UIColor,
        kern: CGFloat = 0,
        lineSpacing: CGFloat = 0,
        alignment: NSTextAlignment = .left
    ) -> NSAttributedString {
        let paragraph = NSMutableParagraphStyle()
        paragraph.lineSpacing = lineSpacing
        paragraph.alignment = alignment
        paragraph.lineBreakMode = .byWordWrapping

        return NSAttributedString(string: string, attributes: [
            .font: font,
            .foregroundColor: color,
            .kern: kern,
            .paragraphStyle: paragraph,
        ])
    }

    private static func height(of string: NSAttributedString, width: CGFloat) -> CGFloat {
        ceil(string.boundingRect(
            with: CGSize(width: width, height: .greatestFiniteMagnitude),
            options: [.usesLineFragmentOrigin, .usesFontLeading],
            context: nil
        ).height)
    }
}
