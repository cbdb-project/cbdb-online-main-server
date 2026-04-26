def _phpunit_test_impl(ctx):
    executable = ctx.actions.declare_file(ctx.label.name)
    runner_path = ctx.file.runner.short_path

    ctx.actions.write(
        output = executable,
        is_executable = True,
        content = """#!/usr/bin/env bash
set -euo pipefail

runner="${{TEST_SRCDIR}}/${{TEST_WORKSPACE}}/{runner_path}"
exec "${{runner}}" "$@"
""".format(
            runner_path = runner_path,
        ),
    )

    runfiles = ctx.runfiles(
        files = [ctx.file.runner],
        transitive_files = depset(transitive = [target[DefaultInfo].files for target in ctx.attr.data]),
    )

    return [DefaultInfo(executable = executable, runfiles = runfiles)]

phpunit_test = rule(
    implementation = _phpunit_test_impl,
    attrs = {
        "data": attr.label_list(allow_files = True),
        "runner": attr.label(
            allow_single_file = True,
            mandatory = True,
        ),
    },
    test = True,
)

def _phpunit_target_name(prefix, test_file):
    name = test_file
    if name.startswith("tests/"):
        name = name[len("tests/"):]
    if name.endswith(".php"):
        name = name[:-len(".php")]

    allowed = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
    normalized = ""
    for index in range(len(name)):
        char = name[index]
        if char in allowed:
            normalized += char
        else:
            normalized += "_"

    return prefix + "__" + normalized

def phpunit_tests(name, test_files, data, runner, size = "medium", timeout = "moderate"):
    tests = []

    for test_file in sorted(test_files):
        target_name = _phpunit_target_name(name, test_file)
        phpunit_test(
            name = target_name,
            args = [
                "--configuration",
                "phpunit.xml",
                test_file,
            ],
            data = data,
            runner = runner,
            size = size,
            timeout = timeout,
        )
        tests.append(":" + target_name)

    native.test_suite(
        name = name,
        tests = tests,
    )
