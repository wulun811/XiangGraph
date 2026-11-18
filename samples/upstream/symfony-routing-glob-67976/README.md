# Real upstream case：Symfony PR #67976

> 状态：`EXTERNAL_REAL_CASE / UPSTREAM_CODE_HARNESS / REPRODUCED / SELF_CHECKED`｜冻结日期：2026-11-13
> 用途：S1-R3 的真实上游语义来源（glob 资源跟踪），不是“XiangGraph 已实现 Symfony 功能”的证据。

## 1. 来源

| 项 | 内容 |
|---|---|
| 项目 | `symfony/symfony` |
| PR | [#67976](https://github.com/symfony/symfony/pull/67976) |
| 标题 | `[DependencyInjection][Routing] Track the glob resource of import() so new files are detected` |
| 合并时间 | 2026-11-10T01:05:57Z |
| 目标分支 | `6.4` |
| 合并提交 | `b82dd4e6f9eef23fe12f5a5512bdb0a70462d1c0` |
| 许可证 | MIT（`LICENSE` 为本目录所取上游许可证副本） |
| 上游问题 | `FileLoader::import()` 解析 glob 后只追踪已匹配文件，丢弃 `GlobResource`；在监视目录中新建符合模式的文件时，配置/路由缓存可能不失效 |
| 修复 | DependencyInjection 的 `FileLoader::import()` 与 Routing 的 YAML/XML/PHP loader、`RoutingConfigurator` 把 `GlobResource` 加入资源集合；即使 glob 当前零匹配也登记 |
| 变更文件 | `DependencyInjection/Loader/FileLoader.php`；`Routing/Loader/Configurator/RoutingConfigurator.php`；`Routing/Loader/Configurator/Traits/GlobResourceTrait.php`；`Routing/Loader/{Php,Xml,Yaml}FileLoader.php` 及测试 |

## 2. 本目录实际运行了什么

`run.sh`：

1. 从 codeload 下载冻结 merge commit 的 monorepo tarball；
2. 校验 tarball SHA-256；
3. 解压 `DependencyInjection`、`Routing`、`Config`、`Filesystem`、`VarExporter` 五个生产组件；
4. 用 `composer.lock` 安装锁定依赖（`symfony/yaml` 8.1.11 等）；
5. 用 `reproduce.php` 直接调用冻结提交中的生产类，执行 8 项行为检查：
   - DI：零匹配 glob 仍被 `ContainerBuilder` 记录为 `GlobResource`；
   - Routing：零匹配 import 仍被 `RouteCollection` 记录；
   - Routing：先只有 `bar.yml` 时只看到 `bar_route`；新增 `baz.yml` 后下一次加载能看到 `baz_route`；
   - 新匹配后资源集合仍包含 glob 资源。

**这不是上游 PHPUnit 测试套件。** 它是冻结上游生产代码上的最小行为 harness；独立性仍是 `SELF_CHECKED`。

## 3. 实测结果

见 `out/run.txt`：**8 checks passed**，日志 SHA-256 `8dacf15683ad12a72436f1941206b04a35bba82de687e09ae0cfa8e97ab477e0`。

环境：PHP 8.5.4 / Composer 2.9.5；日期 2026-11-13。

文件哈希：

| 文件 | SHA-256 |
|---|---|
| `run.sh` | `27a81f4ad4a5cd824bd0fc3acd704cd34c5aefbdfd7396636e65a288e7343a19` |
| `reproduce.php` | `5d969ef934c57de994ca85fd0f06d0d998a39f7e3568fefd40882b3248227f65` |
| `composer.lock` | `24f15176c54b9e3f6f1002e42dab47ea7f85d7d0b8f28db42838797d0d42fad2` |
| `out/run.txt` | `8dacf15683ad12a72436f1941206b04a35bba82de687e09ae0cfa8e97ab477e0` |
| Symfony tarball | `1bdc0d216a8f119ed9b27dabee4ae2a476fd05239f58ab44a66353bc6123f9e5` |

## 4. 这证明什么 / 不证明什么

**证明：**

- 真实上游 Symfony 6.4 代码中，glob 模式本身必须被登记为依赖；
- 零匹配不等于无关：即使当前没有文件，模式仍必须进入资源集合；
- 新匹配文件出现后，下一次加载能发现它；
- 该行为可在冻结 merge commit 上由生产类复跑。

**不证明：**

- XiangGraph 自动发现了该依赖；R3 场景是人根据公开 PR 构造的语义挑战；
- 上游 PHPUnit 全套通过；我们只跑了最小行为 harness；
- 完整 Symfony 应用、生产缓存、真实文件监视器；
- 独立 oracle；仍属 `SELF_CHECKED`；
- G1/G2 运行时能力。

## 5. 复现

```bash
cd samples/upstream/symfony-routing-glob-67976
bash run.sh
```

需要网络访问 `codeload.github.com` 与 Packagist；若离线，无法首次拉取依赖。网络不稳定时 `run.sh` 会重试 3 次。

## 6. 与 R3 领域模型的压力点

- 依赖不能只记录“当前存在的文件”；必须记录**模式本身**与**开放世界匹配规则**。
- 新文件出现时，影响闭包必须增长，而不是因为“基版本没有这条边”而输出 `ALLOW`。
- 旧路由索引/缓存证据必须失效；未受影响的文档分支证据应保留。
- 这正是 R3 `patterns[]` + `analysis_scope.open_prefixes` + `protocol` 的语义来源。
