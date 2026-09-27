// Build a candidate mapping from every Material icon name used in lib/ to a
// HugeIcons stroke-rounded glyph whose name shares the most words. The intent
// is to seed `lib/core/theme/app_icons.dart`; the hand-curated registry edits
// every match that looks wrong.
//
// Run: dart run tool/icon_mapping.dart
//
// Output: prints one line per Material icon as `<material> -> <huge>`.
//
// This file is tooling only; it is intentionally checked into repo so the
// seeding step is reproducible.

import 'dart:io';

void main() {
  final List<String> hugeNames = File(
    '/tmp/hugeicons_all.txt',
  ).readAsLinesSync().where((String l) => l.isNotEmpty).toList();

  // Material icon names actually referenced in lib/, sorted, deduplicated.
  final RegExp materialRef = RegExp(r'(?<![\w\.])Icons\.([a-z0-9_]+)');
  final Set<String> used = <String>{};
  for (final FileSystemEntity e in Directory('lib').listSync(recursive: true)) {
    if (e is! File || !e.path.endsWith('.dart')) continue;
    for (final RegExpMatch m in materialRef.allMatches(e.readAsStringSync())) {
      used.add(m.group(1)!);
    }
  }
  final List<String> ordered = used.toList()..sort();

  for (final String material in ordered) {
    final List<String> words = material
        .replaceAll(RegExp(r'_(rounded|outlined|sharp|filled)$'), '')
        .split('_')
        .where((String w) => w.isNotEmpty)
        .toList();
    // Rank HugeIcons candidates by how many of [material words] appear, in
    // order, in the candidate's tail-stripped name (case-insensitive). This is
    // a heuristic; the registry audit accepts or rejects each suggestion.
    String best = '?';
    int bestScore = -1;
    for (final String huge in hugeNames) {
      final String hugeTail = huge
          .replaceFirst('strokeRounded', '')
          .toLowerCase()
          .replaceAll(RegExp(r'[0-9]+$'), '');
      int score = 0;
      int idx = 0;
      for (final String w in words) {
        final int at = hugeTail.indexOf(w, idx);
        if (at >= 0) {
          score += 1;
          idx = at + w.length;
        }
      }
      // Tie-break on a name containing the *longest* full material word.
      if (score > bestScore ||
          (score == bestScore &&
              score > 0 &&
              hugeTail.contains(material.split('_').first))) {
        bestScore = score;
        best = huge;
      }
    }
    // ignore: avoid_print
    print('$material -> $best (score=$bestScore)');
  }
}
