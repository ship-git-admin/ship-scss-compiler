# Ship SCSS Compiler

WordPressテーマのSCSSを、テーマ内の既存構成を維持したままCSSへコンパイルするプラグインです。

## コンパイル対象

- `wp-content/themes/shipinc/scss/*.scss`
- 出力先：`wp-content/themes/shipinc/css/*.css`
- `_`で始まるファイルはpartialとして扱い、単独出力しません
- 空のトップレベルSCSSは既存CSSを変更せずスキップします

SCSSまたはimportされたpartialが既存CSSより新しい場合だけコンパイルします。新しいCSSは同じディレクトリの一時ファイルで検証してから置換するため、コンパイルエラーや書き込みエラーで既存CSSが失われることはありません。

## GitHub更新

ソースコードは公開リポジトリで管理します。

<https://github.com/ship-git-admin/ship-scss-compiler>

更新チェッカーは`main`ブランチの最新Releaseだけを確認します。GitHub ActionsがReleaseタグから次のAssetを作成し、Assetが存在するReleaseだけをWordPressの更新候補にします。

```text
ship-scss-compiler-x.y.z.zip
```

更新配布にGitHub PATやWordPress側の秘密情報は必要ありません。

## リリース手順

1. `ship-scss-compiler.php`と`readme.txt`のバージョンを更新する
2. `main`へコミットしてpushする
3. `vX.Y.Z`形式のGitHub Releaseを作成する
4. GitHub ActionsがRelease Assetを作成したことを確認する

Release Asset作成後、WordPress管理画面のプラグイン更新で更新候補として検出されます。
