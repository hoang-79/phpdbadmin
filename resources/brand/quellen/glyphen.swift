// Gibt für einen Text die Umrisse als SVG-Pfad aus (y nach unten), je Zeichen ein Pfad.
// Aufruf: swift glyphen.swift <font.ttf> <grösse> <text>
import Foundation
import CoreText
import CoreGraphics

let args = CommandLine.arguments
let url = URL(fileURLWithPath: args[1]) as CFURL
let size = CGFloat(Double(args[2])!)
let text = args[3]
let descs = CTFontManagerCreateFontDescriptorsFromURL(url) as! [CTFontDescriptor]
let font = CTFontCreateWithFontDescriptor(descs[0], size, nil)
let attr = NSAttributedString(string: text, attributes: [kCTFontAttributeName as NSAttributedString.Key: font])
let line = CTLineCreateWithAttributedString(attr)
let runs = CTLineGetGlyphRuns(line) as! [CTRun]
let ascent = CTFontGetAscent(font)
let capHeight = CTFontGetCapHeight(font)
let xHeight = CTFontGetXHeight(font)
var out: [String] = []
func f(_ v: CGFloat) -> String { String(format: "%.2f", Double(v)) }
for run in runs {
    let count = CTRunGetGlyphCount(run)
    var glyphs = [CGGlyph](repeating: 0, count: count)
    var positions = [CGPoint](repeating: .zero, count: count)
    var advances = [CGSize](repeating: .zero, count: count)
    CTRunGetGlyphs(run, CFRange(location: 0, length: 0), &glyphs)
    CTRunGetPositions(run, CFRange(location: 0, length: 0), &positions)
    CTRunGetAdvances(run, CFRange(location: 0, length: 0), &advances)
    for i in 0..<count {
        guard let path = CTFontCreatePathForGlyph(font, glyphs[i], nil) else { out.append("{\"d\":\"\",\"x\":\(f(positions[i].x)),\"adv\":\(f(advances[i].width))}"); continue }
        var d = ""
        let ox = positions[i].x
        path.applyWithBlock { el in
            let p = el.pointee.points
            switch el.pointee.type {
            case .moveToPoint: d += "M\(f(p[0].x + ox)) \(f(-p[0].y))"
            case .addLineToPoint: d += "L\(f(p[0].x + ox)) \(f(-p[0].y))"
            case .addQuadCurveToPoint: d += "Q\(f(p[0].x + ox)) \(f(-p[0].y)) \(f(p[1].x + ox)) \(f(-p[1].y))"
            case .addCurveToPoint: d += "C\(f(p[0].x + ox)) \(f(-p[0].y)) \(f(p[1].x + ox)) \(f(-p[1].y)) \(f(p[2].x + ox)) \(f(-p[2].y))"
            case .closeSubpath: d += "Z"
            @unknown default: break
            }
        }
        out.append("{\"d\":\"\(d)\",\"x\":\(f(ox)),\"adv\":\(f(advances[i].width))}")
    }
}
let width = CTLineGetTypographicBounds(line, nil, nil, nil)
print("{\"width\":\(f(CGFloat(width))),\"ascent\":\(f(ascent)),\"cap\":\(f(capHeight)),\"xheight\":\(f(xHeight)),\"glyphs\":[\(out.joined(separator: ","))]}")
