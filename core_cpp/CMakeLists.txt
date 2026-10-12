cmake_minimum_required(VERSION 3.18)
project(svitanok_raw_core LANGUAGES C CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

include_directories(include)

set(SOURCES
    src/svitanok_raw_core.cpp
    src/cpu_decoder.cpp
    src/vulkan_compute.cpp
)

# Verifica se o compilador CUDA está disponível
include(CheckLanguage)
check_language(CUDA)
if(CMAKE_CUDA_COMPILER)
    enable_language(CUDA)
    set(CMAKE_CUDA_STANDARD 17)
    list(APPEND SOURCES src/cuda_accelerator.cu)
    add_definitions(-DSVITANOK_HAS_CUDA_SDK)
else()
    # Em compilações sem CUDA SDK, compila a camada host C++ com detecção dinâmica
    list(APPEND SOURCES src/cuda_accelerator.cu)
    set_source_files_properties(src/cuda_accelerator.cu PROPERTIES LANGUAGE CXX)
endif()

add_library(svitanok_raw_core SHARED ${SOURCES})

target_compile_definitions(svitanok_raw_core PRIVATE SVITANOK_EXPORTS)

if(WIN32)
    target_link_libraries(svitanok_raw_core PRIVATE kernel32 user32)
endif()

set_target_properties(svitanok_raw_core PROPERTIES
    OUTPUT_NAME "svitanok_raw_core"
    PREFIX ""
)
