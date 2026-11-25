#!/usr/bin/env python3
"""扫描同目录 ./lib 下的模块文件，生成 WebAssembly 内核可加载的两份产物：

  lib.bundle.js    经典脚本，file:// 下用 <script src> 加载（file:// 会被 CORS
                   拦截 fetch，但经典脚本不受限），暴露：
                   globalThis.__MP_LIB_BUNDLE__ = [{path, content}, ...]
  lib/manifest.json http(s) 下按清单逐个 fetch 源文件，改动 .py 后无需重跑本脚本。

页面内核启动后会把这些文件写入 wasm 文件系统的 /lib，从而：
  - os.listdir('/lib') 可见
  - import greeting / from mypkg.utils import double 可用

使用：往 ./lib 里放入 .py / .mpy（支持子目录包），运行：

    python3 make_lib_bundle.py

然后刷新 index.html。http 下若新增/删除文件，重跑一次以更新 manifest 即可；
仅修改文件内容则无需重跑（http 会实时 fetch）。
"""
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
LIB = HERE / 'lib'
OUT_BUNDLE = HERE / 'lib.bundle.js'
OUT_MANIFEST = LIB / 'manifest.json'

# 只映射模块文件与文本资源；排除脚本自身产物
ALLOW_SUFFIX = {'.py', '.mpy', '.json', '.txt'}
SKIP_NAMES = {'manifest.json'}


def iter_files():
    if not LIB.is_dir():
        return
    for p in sorted(LIB.rglob('*')):
        if not p.is_file():
            continue
        rel = p.relative_to(LIB).as_posix()
        if rel in SKIP_NAMES or any(seg.startswith('.') for seg in rel.split('/')):
            continue
        if p.suffix.lower() not in ALLOW_SUFFIX:
            continue
        yield rel, p


def main() -> None:
    if not LIB.is_dir():
        raise SystemExit('未找到 ./lib 目录，请先创建并放入模块文件。')

    files = []
    manifest = []
    for rel, p in iter_files():
        if p.suffix.lower() == '.mpy':
            # .mpy 是二进制，用 base64 传输，写入时二进制还原
            import base64
            content = '@b64:' + base64.b64encode(p.read_bytes()).decode('ascii')
        else:
            content = p.read_text(encoding='utf-8')
        files.append({'path': '/lib/' + rel, 'content': content})
        manifest.append(rel)

    # 经典脚本：用 JSON 保证字符串安全（含引号/换行/中文）
    payload = json.dumps(files, ensure_ascii=False)
    bundle = 'globalThis.__MP_LIB_BUNDLE__ = ' + payload + ';\n'
    OUT_BUNDLE.write_text(bundle, encoding='utf-8')

    OUT_MANIFEST.write_text(
        json.dumps({'files': manifest}, ensure_ascii=False, indent=2) + '\n',
        encoding='utf-8',
    )

    print('已生成 %s（%d 个文件）' % (OUT_BUNDLE.name, len(files)))
    print('已生成 lib/manifest.json')
    for rel in manifest:
        print('  /lib/' + rel)


if __name__ == '__main__':
    main()
